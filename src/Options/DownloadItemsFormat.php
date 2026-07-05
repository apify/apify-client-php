<?php

declare(strict_types=1);

namespace Apify\Client\Options;

/** An output format for downloading dataset items. */
enum DownloadItemsFormat: string
{
    /** JSON array. */
    case JSON = 'json';
    /** Newline-delimited JSON. */
    case JSONL = 'jsonl';
    /** Comma-separated values. */
    case CSV = 'csv';
    /** Microsoft Excel (XLSX) workbook. */
    case XLSX = 'xlsx';
    /** XML. */
    case XML = 'xml';
    /** RSS feed. */
    case RSS = 'rss';
    /** HTML table. */
    case HTML = 'html';
}
