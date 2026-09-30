<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.


namespace local_syllabus;

use local_syllabus\output\document;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/pdflib.php');

/**
 * Exports a syllabus to PDF using the TCPDF library bundled with Moodle.
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_exporter {

    /**
     * Build the PDF content.
     *
     * @param array $data Syllabus data.
     * @return string PDF binary.
     */
    public static function render(array $data): string {
        global $PAGE;

        $renderer = $PAGE->get_renderer('core');
        $html = $renderer->render_from_template('local_syllabus/document',
            (new document($data, true))->export_for_template($renderer));

        $pdf = new \pdf('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetTitle($data['course_name'] ?? '');
        $pdf->SetCreator('Moodle local_syllabus');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(25, 20, 10);
        $pdf->SetAutoPageBreak(true, 20);
        // FreeSerif is a Times-like font with full Cyrillic coverage, shipped with Moodle.
        $pdf->SetFont('freeserif', '', 12);
        $pdf->setCellHeightRatio(1.25);
        // TCPDF adds a blank line around block tags by default; keep the layout compact.
        $none = [['h' => 0, 'n' => 0], ['h' => 0, 'n' => 0]];
        $pdf->setHtmlVSpace([
            'div' => $none,
            'p' => $none,
            'table' => [['h' => 0, 'n' => 0], ['h' => 1, 'n' => 1]],
            'h1' => [['h' => 1, 'n' => 1], ['h' => 0, 'n' => 0]],
            'h2' => $none,
            'h3' => [['h' => 1, 'n' => 1], ['h' => 0, 'n' => 0]],
        ]);
        $pdf->AddPage();
        $pdf->writeHTML(self::prepare_html($html), true, false, true, false, '');
        return $pdf->Output('', 'S');
    }

    /**
     * Adapt the template HTML to what TCPDF supports.
     *
     * @param string $html
     * @return string
     */
    protected static function prepare_html(string $html): string {
        $css = '<style>
            h3 { font-size: 12pt; font-weight: bold; margin-top: 8pt; }
            th { font-weight: bold; text-align: center; }
            td { vertical-align: top; }
            .ls-theme-content { margin-left: 10pt; }
            .ls-theme-hours { font-style: italic; }
            a { color: #1d4ed8; text-decoration: underline; }
        </style>';
        // TCPDF ignores <thead>/<tbody> semantics but repeats thead rows; keep simple.
        $html = preg_replace('/\s+class="[^"]*"/', '', $html);
        return $css . $html;
    }

    /**
     * File name for a download.
     *
     * @param array $data
     * @param string $extension
     * @return string
     */
    public static function filename(array $data, string $extension): string {
        $name = clean_filename(get_string('filenameprefix', 'local_syllabus') . '_' . ($data['course_name'] ?? ''));
        return \core_text::substr($name, 0, 120) . '.' . $extension;
    }
}
