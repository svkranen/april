<?php

namespace App\Service\Export;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelConverter
{
    public function write(string $content, string $destinationPath): void
    {
        $content = preg_replace("/(\r?\n){2,}/", PHP_EOL, $content);
        $normalizedLines = [];
        foreach (preg_split("/\r\n|\n|\r/", $content) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^(?:\s*\[::[^:\]]+::\])+$/', $trimmed)) {
                continue;
            }
            $normalizedLines[] = rtrim($line, "\r\n");
        }
        $content = implode(PHP_EOL, $normalizedLines);

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
