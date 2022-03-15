<?php

namespace OpenSpout\Reader\XLSX;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Reader\Common\Manager\RowManager;
use OpenSpout\Reader\Common\XMLProcessor;
use OpenSpout\Reader\Exception\InvalidValueException;
use OpenSpout\Reader\Exception\XMLProcessingException;
use OpenSpout\Reader\IteratorInterface;
use OpenSpout\Reader\Wrapper\XMLReader;
use OpenSpout\Reader\XLSX\Helper\CellHelper;
use OpenSpout\Reader\XLSX\Helper\CellValueFormatter;

class RowIterator implements IteratorInterface
{
    /** Definition of XML nodes names used to parse data */
    public const XML_NODE_DIMENSION = 'dimension';
    public const XML_NODE_WORKSHEET = 'worksheet';
    public const XML_NODE_ROW = 'row';
    public const XML_NODE_CELL = 'c';

    /** Definition of XML attributes used to parse data */
    public const XML_ATTRIBUTE_REF = 'ref';
    public const XML_ATTRIBUTE_SPANS = 'spans';
    public const XML_ATTRIBUTE_ROW_INDEX = 'r';
    public const XML_ATTRIBUTE_CELL_INDEX = 'r';

    /** @var string Path of the XLSX file being read */
    protected string $filePath;

    /** @var string Path of the sheet data XML file as in [Content_Types].xml */
    protected string $sheetDataXMLFilePath;

    /** @var \OpenSpout\Reader\Wrapper\XMLReader The XMLReader object that will help read sheet's XML data */
    protected \OpenSpout\Reader\Wrapper\XMLReader $xmlReader;

    /** @var \OpenSpout\Reader\Common\XMLProcessor Helper Object to process XML nodes */
    protected \OpenSpout\Reader\Common\XMLProcessor $xmlProcessor;

    /** @var Helper\CellValueFormatter Helper to format cell values */
    protected Helper\CellValueFormatter $cellValueFormatter;

    /** @var \OpenSpout\Reader\Common\Manager\RowManager Manages rows */
    protected \OpenSpout\Reader\Common\Manager\RowManager $rowManager;

    /**
     * TODO: This variable can be deleted when row indices get preserved.
     *
     * @var int Number of read rows
     */
    protected int $numReadRows = 0;

    /** @var Row Contains the row currently processed */
    protected Row $currentlyProcessedRow;

    /** @var null|Row Buffer used to store the current row, while checking if there are more rows to read */
    protected ?Row $rowBuffer;

    /** @var bool Indicates whether all rows have been read */
    protected bool $hasReachedEndOfFile = false;

    /** @var int The number of columns the sheet has (0 meaning undefined) */
    protected int $numColumns = 0;

    /** @var bool Whether empty rows should be returned or skipped */
    protected bool $shouldPreserveEmptyRows;

    /** @var int Last row index processed (one-based) */
    protected int $lastRowIndexProcessed = 0;

    /** @var int Row index to be processed next (one-based) */
    protected int $nextRowIndexToBeProcessed = 0;

    /** @var int Last column index processed (zero-based) */
    protected int $lastColumnIndexProcessed = -1;

    /**
     * @param string             $filePath                Path of the XLSX file being read
     * @param string             $sheetDataXMLFilePath    Path of the sheet data XML file as in [Content_Types].xml
     * @param bool               $shouldPreserveEmptyRows Whether empty rows should be preserved
     * @param XMLReader          $xmlReader               XML Reader
     * @param XMLProcessor       $xmlProcessor            Helper to process XML files
     * @param CellValueFormatter $cellValueFormatter      Helper to format cell values
     * @param RowManager         $rowManager              Manages rows
     */
    public function __construct(
        string $filePath,
        string $sheetDataXMLFilePath,
        bool $shouldPreserveEmptyRows,
        XMLReader $xmlReader,
        XMLProcessor $xmlProcessor,
        CellValueFormatter $cellValueFormatter,
        RowManager $rowManager
    ) {
        $this->filePath = $filePath;
        $this->sheetDataXMLFilePath = $this->normalizeSheetDataXMLFilePath($sheetDataXMLFilePath);
        $this->shouldPreserveEmptyRows = $shouldPreserveEmptyRows;
        $this->xmlReader = $xmlReader;
        $this->cellValueFormatter = $cellValueFormatter;
        $this->rowManager = $rowManager;

        // Register all callbacks to process different nodes when reading the XML file
        $this->xmlProcessor = $xmlProcessor;
        $this->xmlProcessor->registerCallback(self::XML_NODE_DIMENSION, XMLProcessor::NODE_TYPE_START, [$this, 'processDimensionStartingNode']);
        $this->xmlProcessor->registerCallback(self::XML_NODE_ROW, XMLProcessor::NODE_TYPE_START, [$this, 'processRowStartingNode']);
        $this->xmlProcessor->registerCallback(self::XML_NODE_CELL, XMLProcessor::NODE_TYPE_START, [$this, 'processCellStartingNode']);
        $this->xmlProcessor->registerCallback(self::XML_NODE_ROW, XMLProcessor::NODE_TYPE_END, [$this, 'processRowEndingNode']);
        $this->xmlProcessor->registerCallback(self::XML_NODE_WORKSHEET, XMLProcessor::NODE_TYPE_END, [$this, 'processWorksheetEndingNode']);
    }

    /**
     * Rewind the Iterator to the first element.
     * Initializes the XMLReader object that reads the associated sheet data.
     * The XMLReader is configured to be safe from billion laughs attack.
     *
     * @see http://php.net/manual/en/iterator.rewind.php
     *
     * @throws \OpenSpout\Common\Exception\IOException If the sheet data XML cannot be read
     */
    public function rewind(): void
    {
        $this->xmlReader->close();

        if (false === $this->xmlReader->openFileInZip($this->filePath, $this->sheetDataXMLFilePath)) {
            throw new IOException("Could not open \"{$this->sheetDataXMLFilePath}\".");
        }

        $this->numReadRows = 0;
        $this->lastRowIndexProcessed = 0;
        $this->nextRowIndexToBeProcessed = 0;
        $this->rowBuffer = null;
        $this->hasReachedEndOfFile = false;
        $this->numColumns = 0;

        $this->next();
    }

    /**
     * Checks if current position is valid.
     *
     * @see http://php.net/manual/en/iterator.valid.php
     */
    public function valid(): bool
    {
        return !$this->hasReachedEndOfFile;
    }

    /**
     * Move forward to next element. Reads data describing the next unprocessed row.
     *
     * @see http://php.net/manual/en/iterator.next.php
     *
     * @throws \OpenSpout\Reader\Exception\SharedStringNotFoundException If a shared string was not found
     * @throws \OpenSpout\Common\Exception\IOException                   If unable to read the sheet data XML
     */
    public function next(): void
    {
        ++$this->nextRowIndexToBeProcessed;

        if ($this->doesNeedDataForNextRowToBeProcessed()) {
            $this->readDataForNextRow();
        }
    }

    /**
     * Return the current element, either an empty row or from the buffer.
     *
     * @see http://php.net/manual/en/iterator.current.php
     */
    public function current(): ?Row
    {
        $rowToBeProcessed = $this->rowBuffer;

        if ($this->shouldPreserveEmptyRows) {
            // when we need to preserve empty rows, we will either return
            // an empty row or the last row read. This depends whether the
            // index of last row that was read matches the index of the last
            // row whose value should be returned.
            if ($this->lastRowIndexProcessed !== $this->nextRowIndexToBeProcessed) {
                // return empty row if mismatch between last processed row
                // and the row that needs to be returned
                $rowToBeProcessed = new Row([], null);
            }
        }

        return $rowToBeProcessed;
    }

    /**
     * Return the key of the current element. Here, the row index.
     *
     * @see http://php.net/manual/en/iterator.key.php
     */
    public function key(): int
    {
        // TODO: This should return $this->nextRowIndexToBeProcessed
        //       but to avoid a breaking change, the return value for
        //       this function has been kept as the number of rows read.
        return $this->shouldPreserveEmptyRows ?
                $this->nextRowIndexToBeProcessed :
                $this->numReadRows;
    }

    /**
     * Cleans up what was created to iterate over the object.
     */
    public function end(): void
    {
        $this->xmlReader->close();
    }

    /**
     * @param string $sheetDataXMLFilePath Path of the sheet data XML file as in [Content_Types].xml
     *
     * @return string path of the XML file containing the sheet data,
     *                without the leading slash
     */
    protected function normalizeSheetDataXMLFilePath(string $sheetDataXMLFilePath): string
    {
        return ltrim($sheetDataXMLFilePath, '/');
    }

    /**
     * Returns whether we need data for the next row to be processed.
     * We don't need to read data if:
     *   we have already read at least one row
     *     AND
     *   we need to preserve empty rows
     *     AND
     *   the last row that was read is not the row that need to be processed
     *   (i.e. if we need to return empty rows).
     *
     * @return bool whether we need data for the next row to be processed
     */
    protected function doesNeedDataForNextRowToBeProcessed(): bool
    {
        $hasReadAtLeastOneRow = (0 !== $this->lastRowIndexProcessed);

        return
            !$hasReadAtLeastOneRow
            || !$this->shouldPreserveEmptyRows
            || $this->lastRowIndexProcessed < $this->nextRowIndexToBeProcessed
        ;
    }

    /**
     * @throws \OpenSpout\Reader\Exception\SharedStringNotFoundException If a shared string was not found
     * @throws \OpenSpout\Common\Exception\IOException                   If unable to read the sheet data XML
     */
    protected function readDataForNextRow()
    {
        $this->currentlyProcessedRow = new Row([], null);

        try {
            $this->xmlProcessor->readUntilStopped();
        } catch (XMLProcessingException $exception) {
            throw new IOException("The {$this->sheetDataXMLFilePath} file cannot be read. [{$exception->getMessage()}]");
        }

        $this->rowBuffer = $this->currentlyProcessedRow;
    }

    /**
     * @param \OpenSpout\Reader\Wrapper\XMLReader $xmlReader XMLReader object, positioned on a "<dimension>" starting node
     *
     * @return int A return code that indicates what action should the processor take next
     */
    protected function processDimensionStartingNode(XMLReader $xmlReader): int
    {
        // Read dimensions of the sheet
        $dimensionRef = $xmlReader->getAttribute(self::XML_ATTRIBUTE_REF); // returns 'A1:M13' for instance (or 'A1' for empty sheet)
        if (preg_match('/[A-Z]+\d+:([A-Z]+\d+)/', $dimensionRef, $matches)) {
            $this->numColumns = CellHelper::getColumnIndexFromCellIndex($matches[1]) + 1;
        }

        return XMLProcessor::PROCESSING_CONTINUE;
    }

    /**
     * @param \OpenSpout\Reader\Wrapper\XMLReader $xmlReader XMLReader object, positioned on a "<row>" starting node
     *
     * @return int A return code that indicates what action should the processor take next
     */
    protected function processRowStartingNode(XMLReader $xmlReader): int
    {
        // Reset index of the last processed column
        $this->lastColumnIndexProcessed = -1;

        // Mark the last processed row as the one currently being read
        $this->lastRowIndexProcessed = $this->getRowIndex($xmlReader);

        // Read spans info if present
        $numberOfColumnsForRow = $this->numColumns;
        $spans = $xmlReader->getAttribute(self::XML_ATTRIBUTE_SPANS); // returns '1:5' for instance
        if ($spans) {
            [, $numberOfColumnsForRow] = explode(':', $spans);
            $numberOfColumnsForRow = (int) $numberOfColumnsForRow;
        }

        $cells = array_fill(0, $numberOfColumnsForRow, new Cell(''));
        $this->currentlyProcessedRow->setCells($cells);

        return XMLProcessor::PROCESSING_CONTINUE;
    }

    /**
     * @param \OpenSpout\Reader\Wrapper\XMLReader $xmlReader XMLReader object, positioned on a "<cell>" starting node
     *
     * @return int A return code that indicates what action should the processor take next
     */
    protected function processCellStartingNode(XMLReader $xmlReader): int
    {
        $currentColumnIndex = $this->getColumnIndex($xmlReader);

        // NOTE: expand() will automatically decode all XML entities of the child nodes
        /** @var \DOMElement $node */
        $node = $xmlReader->expand();
        $cell = $this->getCell($node);

        $this->currentlyProcessedRow->setCellAtIndex($cell, $currentColumnIndex);
        $this->lastColumnIndexProcessed = $currentColumnIndex;

        return XMLProcessor::PROCESSING_CONTINUE;
    }

    /**
     * @return int A return code that indicates what action should the processor take next
     */
    protected function processRowEndingNode(): int
    {
        // if the fetched row is empty and we don't want to preserve it..,
        if (!$this->shouldPreserveEmptyRows && $this->rowManager->isEmpty($this->currentlyProcessedRow)) {
            // ... skip it
            return XMLProcessor::PROCESSING_CONTINUE;
        }

        ++$this->numReadRows;

        // If needed, we fill the empty cells
        if (0 === $this->numColumns) {
            $this->currentlyProcessedRow = $this->rowManager->fillMissingIndexesWithEmptyCells($this->currentlyProcessedRow);
        }

        // at this point, we have all the data we need for the row
        // so that we can populate the buffer
        return XMLProcessor::PROCESSING_STOP;
    }

    /**
     * @return int A return code that indicates what action should the processor take next
     */
    protected function processWorksheetEndingNode(): int
    {
        // The closing "</worksheet>" marks the end of the file
        $this->hasReachedEndOfFile = true;

        return XMLProcessor::PROCESSING_STOP;
    }

    /**
     * @param \OpenSpout\Reader\Wrapper\XMLReader $xmlReader XMLReader object, positioned on a "<row>" node
     *
     * @throws \OpenSpout\Common\Exception\InvalidArgumentException When the given cell index is invalid
     *
     * @return int Row index
     */
    protected function getRowIndex(XMLReader $xmlReader): int
    {
        // Get "r" attribute if present (from something like <row r="3"...>
        $currentRowIndex = $xmlReader->getAttribute(self::XML_ATTRIBUTE_ROW_INDEX);

        return (null !== $currentRowIndex) ?
                (int) $currentRowIndex :
                $this->lastRowIndexProcessed + 1;
    }

    /**
     * @param \OpenSpout\Reader\Wrapper\XMLReader $xmlReader XMLReader object, positioned on a "<c>" node
     *
     * @throws \OpenSpout\Common\Exception\InvalidArgumentException When the given cell index is invalid
     *
     * @return int Column index
     */
    protected function getColumnIndex(XMLReader $xmlReader): int
    {
        // Get "r" attribute if present (from something like <c r="A1"...>
        $currentCellIndex = $xmlReader->getAttribute(self::XML_ATTRIBUTE_CELL_INDEX);

        return (null !== $currentCellIndex) ?
                CellHelper::getColumnIndexFromCellIndex($currentCellIndex) :
                $this->lastColumnIndexProcessed + 1;
    }

    /**
     * Returns the cell with (unescaped) correctly marshalled, cell value associated to the given XML node.
     *
     * @return Cell The cell set with the associated with the cell
     */
    protected function getCell(\DOMElement $node): Cell
    {
        try {
            $cellValue = $this->cellValueFormatter->extractAndFormatNodeValue($node);
            $cell = new Cell($cellValue);
        } catch (InvalidValueException $exception) {
            $cell = new Cell($exception->getInvalidValue());
            $cell->setType(Cell::TYPE_ERROR);
        }

        return $cell;
    }
}
