<?php
require_once('fpdf.php');

class PDF_UTF8 extends FPDF {
    function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='') {
        $txt = iconv('UTF-8', 'windows-1252//IGNORE', $txt);
        parent::Cell($w, $h, $txt, $border, $ln, $align, $fill, $link);
    }
    
    function MultiCell($w, $h, $txt, $border=0, $align='J', $fill=false) {
        $txt = iconv('UTF-8', 'windows-1252//IGNORE', $txt);
        parent::MultiCell($w, $h, $txt, $border, $align, $fill);
    }
    
    function Write($h, $txt, $link='') {
        $txt = iconv('UTF-8', 'windows-1252//IGNORE', $txt);
        parent::Write($h, $txt, $link);
    }
    
    function Text($x, $y, $txt) {
        $txt = iconv('UTF-8', 'windows-1252//IGNORE', $txt);
        parent::Text($x, $y, $txt);
    }
}
?>