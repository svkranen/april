<?php

namespace App\Service\Export;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelConverter
{
    public function write(string $content, string $destinationPath): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'amagno_csv');
        file_put_contents($temp, $content);

        $reader = IOFactory::createReader('Csv');
        $reader->setDelimiter('|');
        $reader->setEnclosure('');
        $reader->setSheetIndex(0);
        $spreadsheet = $reader->load($temp);

        $writer = new Xlsx($spreadsheet);
        $writer->save($destinationPath);

        unlink($temp);
    }
}
