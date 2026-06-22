<?php
/*
 * Thin wrapper for the printable unit worksheet.
 * The original renderer lives in unit_pdf_base.php. This wrapper injects
 * print-only typography overrides without changing the worksheet renderer.
 */
ob_start();
require __DIR__ . '/unit_pdf_base.php';
$html = ob_get_clean();

$printFontCss = <<<'CSS'

/* Print font override: keep header/background colours, make worksheet content readable. */
@media print {
  .ws-body,
  .ws-body :is(.unit-sub,.instr-row,.itxt,.ws-qt,.ws-opt,.ws-expl,.ws-chip,.ws-fr,.ws-fill-prompt,.ws-wi,.ws-ma,.mrow,.ml,.mn,.ws-or,.dt-num,.rc-text,.fc-word,.tc-w,table.ws-tbl,table.ws-tbl td,table.ws-tbl th) {
    color: #000 !important;
    font-size: 12pt !important;
    line-height: 1.45 !important;
  }

  .ws-body :is(.sec-title,.unit-title) {
    color: #000 !important;
    font-size: 14pt !important;
  }
}
CSS;

if (strpos($html, '</style>') !== false) {
    $html = preg_replace('/<\/style>/', $printFontCss . "\n</style>", $html, 1);
} else {
    $html = str_replace('</head>', '<style>' . $printFontCss . '</style></head>', $html);
}

echo $html;
