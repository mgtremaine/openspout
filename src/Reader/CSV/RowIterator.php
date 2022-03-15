<?php

namespace OpenSpout\Reader\CSV;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Helper\EncodingHelper;
use OpenSpout\Common\Manager\OptionsManagerInterface;
use OpenSpout\Reader\Common\Entity\Options;
use OpenSpout\Reader\RowIteratorInterface;

/**
 * Iterate over CSV rows.
 */
class RowIterator implements RowIteratorInterface
{
    /**
     * Value passed to fgetcsv. 0 means "unlimited" (slightly slower but accomodates for very long lines).
     */
    public const MAX_READ_BYTES_PER_LINE = 0;

    /** @var null|resource Pointer to the CSV file to read */
    protected $filePointer;

    /** @var int Number of read rows */
    protected int $numReadRows = 0;

    /** @var null|Row Buffer used to store the current row, while checking if there are more rows to read */
    protected ?Row $rowBuffer;

    /** @var bool Indicates whether all rows have been read */
    protected bool $hasReachedEndOfFile = false;

    /** @var string Defines the character used to delimit fields (one character only) */
    protected string $fieldDelimiter;

    /** @var string Defines the character used to enclose fields (one character only) */
    protected string $fieldEnclosure;

    /** @var string Encoding of the CSV file to be read */
    protected string $encoding;

    /** @var bool Whether empty rows should be returned or skipped */
    protected bool $shouldPreserveEmptyRows;

    /** @var \OpenSpout\Common\Helper\EncodingHelper Helper to work with different encodings */
    protected \OpenSpout\Common\Helper\EncodingHelper $encodingHelper;

    /**
     * @param resource $filePointer Pointer to the CSV file to read
     */
    public function __construct(
        $filePointer,
        OptionsManagerInterface $optionsManager,
        EncodingHelper $encodingHelper
    ) {
        $this->filePointer = $filePointer;
        $this->fieldDelimiter = $optionsManager->getOption(Options::FIELD_DELIMITER);
        $this->fieldEnclosure = $optionsManager->getOption(Options::FIELD_ENCLOSURE);
        $this->encoding = $optionsManager->getOption(Options::ENCODING);
        $this->shouldPreserveEmptyRows = $optionsManager->getOption(Options::SHOULD_PRESERVE_EMPTY_ROWS);
        $this->encodingHelper = $encodingHelper;
    }

    /**
     * Rewind the Iterator to the first element.
     *
     * @see http://php.net/manual/en/iterator.rewind.php
     */
    public function rewind(): void
    {
        $this->rewindAndSkipBom();

        $this->numReadRows = 0;
        $this->rowBuffer = null;

        $this->next();
    }

    /**
     * Checks if current position is valid.
     *
     * @see http://php.net/manual/en/iterator.valid.php
     */
    public function valid(): bool
    {
        return $this->filePointer && !$this->hasReachedEndOfFile;
    }

    /**
     * Move forward to next element. Reads data for the next unprocessed row.
     *
     * @see http://php.net/manual/en/iterator.next.php
     *
     * @throws \OpenSpout\Common\Exception\EncodingConversionException If unable to convert data to UTF-8
     */
    public function next(): void
    {
        $this->hasReachedEndOfFile = feof($this->filePointer);

        if (!$this->hasReachedEndOfFile) {
            $this->readDataForNextRow();
        }
    }

    /**
     * Return the current element from the buffer.
     *
     * @see http://php.net/manual/en/iterator.current.php
     */
    public function current(): ?Row
    {
        return $this->rowBuffer;
    }

    /**
     * Return the key of the current element.
     *
     * @see http://php.net/manual/en/iterator.key.php
     */
    public function key(): int
    {
        return $this->numReadRows;
    }

    /**
     * Cleans up what was created to iterate over the object.
     */
    public function end(): void
    {
        // do nothing
    }

    /**
     * This rewinds and skips the BOM if inserted at the beginning of the file
     * by moving the file pointer after it, so that it is not read.
     */
    protected function rewindAndSkipBom()
    {
        $byteOffsetToSkipBom = $this->encodingHelper->getBytesOffsetToSkipBOM($this->filePointer, $this->encoding);

        // sets the cursor after the BOM (0 means no BOM, so rewind it)
        fseek($this->filePointer, $byteOffsetToSkipBom);
    }

    /**
     * @throws \OpenSpout\Common\Exception\EncodingConversionException If unable to convert data to UTF-8
     */
    protected function readDataForNextRow()
    {
        do {
            $rowData = $this->getNextUTF8EncodedRow();
        } while ($this->shouldReadNextRow($rowData));

        if (false !== $rowData) {
            // array_map will replace NULL values by empty strings
            $rowDataBufferAsArray = array_map('\\strval', $rowData);
            $this->rowBuffer = new Row(array_map(function ($cellValue) {
                return new Cell($cellValue);
            }, $rowDataBufferAsArray), null);
            ++$this->numReadRows;
        } else {
            // If we reach this point, it means end of file was reached.
            // This happens when the last lines are empty lines.
            $this->hasReachedEndOfFile = true;
        }
    }

    /**
     * @param array|bool $currentRowData
     *
     * @return bool Whether the data for the current row can be returned or if we need to keep reading
     */
    protected function shouldReadNextRow($currentRowData): bool
    {
        $hasSuccessfullyFetchedRowData = (false !== $currentRowData);
        $hasNowReachedEndOfFile = feof($this->filePointer);
        $isEmptyLine = $this->isEmptyLine($currentRowData);

        return
            (!$hasSuccessfullyFetchedRowData && !$hasNowReachedEndOfFile)
            || (!$this->shouldPreserveEmptyRows && $isEmptyLine)
        ;
    }

    /**
     * Returns the next row, converted if necessary to UTF-8.
     * As fgetcsv() does not manage correctly encoding for non UTF-8 data,
     * we remove manually whitespace with ltrim or rtrim (depending on the order of the bytes).
     *
     * @throws \OpenSpout\Common\Exception\EncodingConversionException If unable to convert data to UTF-8
     *
     * @return array|false The row for the current file pointer, encoded in UTF-8 or FALSE if nothing to read
     */
    protected function getNextUTF8EncodedRow()
    {
        $encodedRowData = fgetcsv($this->filePointer, self::MAX_READ_BYTES_PER_LINE, $this->fieldDelimiter, $this->fieldEnclosure, '');
        if (false === $encodedRowData) {
            return false;
        }

        foreach ($encodedRowData as $cellIndex => $cellValue) {
            switch ($this->encoding) {
                case EncodingHelper::ENCODING_UTF16_LE:
                case EncodingHelper::ENCODING_UTF32_LE:
                    // remove whitespace from the beginning of a string as fgetcsv() add extra whitespace when it try to explode non UTF-8 data
                    $cellValue = ltrim($cellValue);

                    break;

                case EncodingHelper::ENCODING_UTF16_BE:
                case EncodingHelper::ENCODING_UTF32_BE:
                    // remove whitespace from the end of a string as fgetcsv() add extra whitespace when it try to explode non UTF-8 data
                    $cellValue = rtrim($cellValue);

                    break;
            }

            $encodedRowData[$cellIndex] = $this->encodingHelper->attemptConversionToUTF8($cellValue, $this->encoding);
        }

        return $encodedRowData;
    }

    /**
     * @param array|bool $lineData Array containing the cells value for the line
     *
     * @return bool Whether the given line is empty
     */
    protected function isEmptyLine($lineData): bool
    {
        return \is_array($lineData) && 1 === \count($lineData) && null === $lineData[0];
    }
}
