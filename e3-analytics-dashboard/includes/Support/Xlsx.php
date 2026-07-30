<?php
namespace E3_Analytics\Support;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Minimal XLSX writer (no external libraries).
 * Generates sheets using inline strings for broad compatibility.
 */
final class Xlsx {

    /**
     * @param string $filename
     * @param array $sheets Array of [ 'name' => string, 'rows' => array<array<mixed>> ]
     */
    public static function download( $filename, $sheets ) {
        if ( ! class_exists( '\\ZipArchive' ) ) {
            wp_die( 'ZipArchive no está disponible en este servidor. No es posible generar el Excel.' );
        }

        $sheets = is_array( $sheets ) ? $sheets : [];
        if ( empty( $sheets ) ) {
            $sheets = [ [ 'name' => 'Hoja1', 'rows' => [ [ 'Sin datos' ] ] ] ];
        }

        $safe = preg_replace( '/[^a-zA-Z0-9\-_. ]+/', '_', (string) $filename );
        $safe = trim( $safe );
        if ( $safe === '' ) $safe = 'reporte.xlsx';
        if ( substr( $safe, -5 ) !== '.xlsx' ) $safe .= '.xlsx';

        $tmp = tempnam( sys_get_temp_dir(), 'e3a_xlsx_' );
        if ( ! $tmp ) {
            wp_die( 'No se pudo crear un archivo temporal para generar el Excel.' );
        }

        $zip = new \ZipArchive();
        if ( true !== $zip->open( $tmp, \ZipArchive::OVERWRITE ) ) {
            @unlink( $tmp );
            wp_die( 'No se pudo abrir el contenedor ZIP para generar el Excel.' );
        }

        $sheetCount = count( $sheets );

        // Core files
        $zip->addFromString( '[Content_Types].xml', self::content_types_xml( $sheetCount ) );
        $zip->addFromString( '_rels/.rels', self::root_rels_xml() );
        $zip->addFromString( 'docProps/core.xml', self::core_xml() );
        $zip->addFromString( 'docProps/app.xml', self::app_xml( $sheetCount ) );

        // Workbook
        $zip->addFromString( 'xl/workbook.xml', self::workbook_xml( $sheets ) );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels', self::workbook_rels_xml( $sheetCount ) );
        $zip->addFromString( 'xl/styles.xml', self::styles_xml() );

        // Sheets
        for ( $i = 0; $i < $sheetCount; $i++ ) {
            $rows = $sheets[$i]['rows'] ?? [];
            if ( ! is_array( $rows ) ) $rows = [];
            $zip->addFromString( 'xl/worksheets/sheet' . ($i+1) . '.xml', self::sheet_xml( $rows ) );
        }

        $zip->close();

        nocache_headers();
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $safe . '"' );
        header( 'X-Content-Type-Options: nosniff' );

        // Stream
        $size = filesize( $tmp );
        if ( $size ) header( 'Content-Length: ' . (int) $size );

        readfile( $tmp );
        @unlink( $tmp );
        exit;
    }

    private static function content_types_xml( $sheetCount ) {
        $overrides = '';
        for ( $i = 1; $i <= $sheetCount; $i++ ) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $overrides
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private static function root_rels_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private static function workbook_rels_xml( $sheetCount ) {
        $rels = '';
        $rid = 1;
        for ( $i = 1; $i <= $sheetCount; $i++ ) {
            $rels .= '<Relationship Id="rId' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $rid++;
        }
        $rels .= '<Relationship Id="rId' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    private static function workbook_xml( $sheets ) {
        $sheetXml = '';
        $i = 1;
        foreach ( $sheets as $sheet ) {
            $name = isset( $sheet['name'] ) ? (string) $sheet['name'] : 'Hoja' . $i;
            $name = trim( $name );
            if ( $name === '' ) $name = 'Hoja' . $i;
            $name = self::sanitize_sheet_name( $name );

            $sheetXml .= '<sheet name="' . self::xml( $name ) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $i++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetXml . '</sheets>'
            . '</workbook>';
    }

    private static function styles_xml() {
        // Minimal style sheet.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '</styleSheet>';
    }

    private static function sheet_xml( $rows ) {
        $rowsXml = '';
        $r = 1;

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) $row = [ $row ];

            $cellsXml = '';
            $c = 0;
            foreach ( $row as $value ) {
                $c++;
                $ref = self::col_letter( $c ) . $r;

                if ( is_int( $value ) || is_float( $value ) ) {
                    $cellsXml .= '<c r="' . $ref . '"><v>' . self::xml( (string) $value ) . '</v></c>';
                } elseif ( is_bool( $value ) ) {
                    $cellsXml .= '<c r="' . $ref . '" t="b"><v>' . ( $value ? '1' : '0' ) . '</v></c>';
                } elseif ( null === $value ) {
                    $cellsXml .= '<c r="' . $ref . '"/>';
                } else {
                    $text = (string) $value;
                    $cellsXml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . self::xml( $text ) . '</t></is></c>';
                }
            }

            $rowsXml .= '<row r="' . $r . '">' . $cellsXml . '</row>';
            $r++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . '</worksheet>';
    }

    private static function core_xml() {
        $now = gmdate( 'Y-m-d\TH:i:s\Z' );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>E3 Analytics Export</dc:title>'
            . '<dc:creator>E3 Analytics Dashboard</dc:creator>'
            . '<cp:lastModifiedBy>E3 Analytics Dashboard</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private static function app_xml( $sheetCount ) {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Microsoft Excel</Application>'
            . '<DocSecurity>0</DocSecurity>'
            . '<ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant">'
            . '<vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant>'
            . '<vt:variant><vt:i4>' . (int) $sheetCount . '</vt:i4></vt:variant>'
            . '</vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="' . (int) $sheetCount . '" baseType="lpstr">'
            . self::titles_vector_xml( $sheetCount )
            . '</vt:vector></TitlesOfParts>'
            . '<Company></Company>'
            . '<LinksUpToDate>false</LinksUpToDate>'
            . '<SharedDoc>false</SharedDoc>'
            . '<HyperlinksChanged>false</HyperlinksChanged>'
            . '<AppVersion>16.0000</AppVersion>'
            . '</Properties>';
    }

    private static function titles_vector_xml( $sheetCount ) {
        $out = '';
        for ( $i = 1; $i <= $sheetCount; $i++ ) {
            $out .= '<vt:lpstr>Hoja' . $i . '</vt:lpstr>';
        }
        return $out;
    }

    private static function col_letter( $n ) {
        $n = (int) $n;
        $s = '';
        while ( $n > 0 ) {
            $m = ( $n - 1 ) % 26;
            $s = chr( 65 + $m ) . $s;
            $n = (int) floor( ( $n - 1 ) / 26 );
        }
        return $s ?: 'A';
    }

    private static function xml( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
    }

    private static function sanitize_sheet_name( $name ) {
        // Excel sheet name rules: max 31 chars, no []:*?/\\
        $name = preg_replace( '/[\\[\\]\\*\\?\\/\\\\:]/', ' ', (string) $name );
        $name = trim( preg_replace( '/\s+/', ' ', $name ) );
        if ( $name === '' ) $name = 'Hoja';
        if ( function_exists( 'mb_substr' ) ) {
            $name = mb_substr( $name, 0, 31 );
        } else {
            $name = substr( $name, 0, 31 );
        }
        return $name;
    }
}
