<?php
declare(strict_types=1);

/* ============================================================
   MPC-IEMS — MISRAK POLYTECHNIC COLLEGE
   PDF / PRINT EXPORT MODULE (Exact A4 Template Match)
   ============================================================ */

if (
    PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Direct access forbidden.');
}

require_once __DIR__ . '/../functions.php';

if (!class_exists('PdfExport', false)) {

    final class PdfExport
    {
        private static ?string $engineCache = null;

        public static function engine(): string
        {
            if (self::$engineCache !== null) return self::$engineCache;

            if (class_exists('\Mpdf\Mpdf'))       return self::$engineCache = 'mpdf';
            if (class_exists('\TCPDF'))           return self::$engineCache = 'tcpdf';
            if (class_exists('\Dompdf\Dompdf'))   return self::$engineCache = 'dompdf';
            
            return self::$engineCache = 'browser';
        }

        public static function hasRealPdf(): bool
        {
            return self::engine() !== 'browser';
        }

        /**
         * Build the full A4 document matching the Word templates.
         */
        public static function letter(array $opts): string
        {
            $to       = (string)($opts['to'] ?? '');
            $toAm     = (string)($opts['to_am'] ?? '');
            $toOrg    = (string)($opts['to_org'] ?? '');
            $subject  = (string)($opts['subject'] ?? '');
            $subjectAm= (string)($opts['subject_am'] ?? '');
            $bodyRaw  = (string)($opts['body'] ?? '');
            
            // For complex tables (like the Melka Adama example)
            $customTable = (string)($opts['custom_table'] ?? '');

            $refNo    = (string)($opts['ref_no'] ?? '');
            $docNo    = (string)($opts['doc_no'] ?? '');
            $memoNo   = (string)($opts['memo_no'] ?? '');
            $dateStr  = (string)($opts['date'] ?? date('Y-m-d'));
            $isInternal = (bool)($opts['is_internal'] ?? false);

            $signerName = (string)($opts['signer_name'] ?? '');
            $signerPos  = (string)($opts['signer_position'] ?? '');
            $signerDept = (string)($opts['signer_dept'] ?? '');
            $cc         = (string)($opts['cc'] ?? '');

            // 1. Header Table
            $body = self::letterhead($isInternal, $docNo, $memoNo);

            // 2. Body Content based on type
            if ($isInternal) {
                // ==========================================
                // INTERNAL MEMO
                // ==========================================
                
                // Date block (Red text like the image)
                $dateDisplay = date('d/m/Y', strtotime($dateStr));
                $body .= '<div style="text-align:right; margin:15px 0 20px 0; font-weight:bold; font-family:\'Nyala\', sans-serif; font-size:14px;">'
                       .   'ቀን: <span style="color:#DC2626;">' . $dateDisplay . ' ዓ.ም</span>'
                       . '</div>';

                // To block
                if ($to !== '' || $toAm !== '') {
                    $body .= '<div style="margin-bottom:15px; font-size:14px; font-family:\'Nyala\', sans-serif;">'
                           .   '<strong>ለ:</strong> ' . self::escape($toAm ?: $to)
                           . '</div>';
                }

                // Subject block
                if ($subject !== '' || $subjectAm !== '') {
                    $body .= '<div style="margin-bottom:20px; font-size:14px; font-family:\'Nyala\', sans-serif;">'
                           .   '<strong><u>ጉዳዩ:-</u></strong> ' . self::escape($subjectAm ?: $subject)
                           . '</div>';
                }

                // Body Content
                $body .= '<div style="font-size:14px; line-height:1.8; font-family:\'Nyala\', sans-serif; text-align:justify;">';
                if ($bodyRaw) $body .= '<p style="white-space:pre-wrap;">' . self::escape($bodyRaw) . '</p>';
                if ($customTable) $body .= $customTable;
                $body .= '</div>';

                // Signature
                $body .= '<div style="margin-top:50px; text-align:right; font-size:14px; font-family:\'Nyala\', sans-serif; line-height:1.5;">';
                $body .= '<p style="margin-bottom:35px; font-weight:bold;">በትህትና,</p>';
                $body .= '<p><strong>' . self::escape($signerName) . '</strong><br>';
                if ($signerPos !== '') $body .= self::escape($signerPos) . '<br>';
                if ($signerDept !== '') $body .= self::escape($signerDept);
                $body .= '</p></div>';

            } else {
                // ==========================================
                // EXTERNAL LETTER
                // ==========================================

                // Ref & Date block (Red text for dynamic values)
                $body .= '<div style="text-align:right; margin:20px 0 20px 0; font-size:14px; line-height:1.5; font-family:\'Nyala\', sans-serif;">'
                       .   '<div>Ref No. <span style="color:#DC2626; font-weight:bold;">' . self::escape($refNo) . '</span></div>'
                       .   '<div>ቀን / Date <span style="color:#DC2626; font-weight:bold;">' . format_date($dateStr) . '</span></div>'
                       . '</div>';

                // To block
                $body .= '<div style="margin-bottom:20px; font-size:14px; line-height:1.4; font-family:\'Nyala\', sans-serif;">'
                       .   '<strong>To: ' . self::escape($to) . '</strong><br>'
                       .   ($toOrg !== '' ? self::escape($toOrg) : '')
                       . '</div>';

                // Subject block
                if ($subject !== '') {
                    $body .= '<div style="margin-bottom:20px; font-size:14px; line-height:1.4; font-family:\'Nyala\', sans-serif;">'
                           .   '<strong><u>Subject:</u> ' . self::escape($subject) . '</strong>'
                           . '</div>';
                }

                // Body Content
                $body .= '<div style="font-size:14px; line-height:1.8; font-family:\'Nyala\', sans-serif; text-align:justify;">';
                if ($bodyRaw) $body .= '<p style="white-space:pre-wrap;">' . self::escape($bodyRaw) . '</p>';
                if ($customTable) $body .= $customTable;
                $body .= '</div>';

                // Signature
                $body .= '<div style="margin-top:40px; text-align:right; font-size:14px; font-family:\'Nyala\', sans-serif; line-height:1.4;">';
                $body .= '<p>Sincerely,</p>';
                $body .= '<br><br>';
                $body .= '<p><strong>' . self::escape($signerName) . '</strong><br>';
                if ($signerPos !== '') $body .= self::escape($signerPos) . '<br>';
                if ($signerDept !== '') $body .= self::escape($signerDept);
                $body .= '</p></div>';

                // C.C. Block
                if ($cc) {
                    $body .= '<div style="margin-top:30px; font-size:13px; line-height:1.4; font-family:\'Nyala\', sans-serif;">';
                    $body .= '<strong style="text-decoration:underline;">C.C.:</strong><ul style="margin:5px 0 0 20px; padding:0;">';
                    foreach (explode("\n", $cc) as $line) {
                        if (trim($line)) $body .= '<li>' . self::escape(trim($line)) . '</li>';
                    }
                    $body .= '</ul></div>';
                }
            }

            return self::html($body, [
                'title'       => $subject ?: 'Official Document', 
                'is_internal' => $isInternal
            ]);
        }

        /**
         * Generates the exact complex bordered header table.
         */
        public static function letterhead(bool $isInternal = false, string $docNo = '', string $memoNo = ''): string
        {
            $orgAm = get_setting('org_name_am', 'ምሥራቅ ፖሊ ቴክኒክ ኮሌጅ');
            $orgEn = get_setting('org_name_en', 'MISRAK POLYTECHNIC COLLEGE');
            $logo  = get_logo_url();

            if ($docNo === '') {
                $docNo = $isInternal ? get_setting('doc_no_internal', 'OF/MPTC/072') : get_setting('doc_no_external', 'OF/MPTC/058');
            }
            if ($memoNo === '') $memoNo = '01';

            $titleAm = $isInternal ? 'የውስጥ ማስታወሻ' : 'ውጪ ደብዳቤ';
            $titleEn = $isInternal ? 'INTERNAL MEMO' : 'Outgoing Letter';

            return '<table style="width:100%; border-collapse:collapse; border:2px solid #000; font-family:\'Nyala\', Arial, sans-serif; margin-bottom:15px;">'
                 .   '<tr>'
                 // Logo Cell
                 .     '<td rowspan="2" style="width:18%; border:1px solid #000; text-align:center; padding:5px; vertical-align:middle;">'
                 .       '<img src="' . self::escape($logo) . '" alt="Logo" style="width:80px; height:auto;">'
                 .     '</td>'
                 // Company Name Cell
                 .     '<td colspan="2" style="width:57%; border:1px solid #000; text-align:center; padding:10px; vertical-align:middle;">'
                 .       '<div style="font-size:9px; text-align:left; margin-bottom:5px;">የተቋም ስም<br>Company Name</div>'
                 .       '<div style="font-size:20px; font-weight:bold; line-height:1.2;">' . self::escape($orgAm) . '</div>'
                 .       '<div style="font-size:16px; font-weight:bold; margin-top:3px;">' . self::escape($orgEn) . '</div>'
                 .     '</td>'
                 // Document No Cell
                 .     '<td style="width:25%; border:1px solid #000; text-align:center; padding:5px; vertical-align:middle;">'
                 .       '<div style="font-size:9px; text-align:left; margin-bottom:5px;">የሰነድ ቁጥር<br>Document No.</div>'
                 .       '<div style="font-size:14px; font-weight:bold;">' . self::escape($docNo) . '</div>'
                 .     '</td>'
                 .   '</tr>'
                 .   '<tr>'
                 // Title Cell
                 .     '<td style="width:37%; border:1px solid #000; text-align:center; padding:5px; vertical-align:middle;">'
                 .       '<div style="font-size:9px; text-align:left; margin-bottom:2px;">ርዕስ<br>Title</div>'
                 .       '<div style="font-size:14px; font-weight:bold; text-decoration:underline; margin-top:2px;">' . self::escape($titleAm) . '<br>' . self::escape($titleEn) . '</div>'
                 .     '</td>'
                 // Issue No Cell
                 .     '<td style="width:20%; border:1px solid #000; text-align:center; padding:5px; vertical-align:middle;">'
                 .       '<div style="font-size:9px; text-align:left; margin-bottom:2px;">ዕትም ቁጥር<br>Issue No.</div>'
                 .       '<div style="font-size:14px; font-weight:bold; margin-top:2px;">' . self::escape($memoNo) . '</div>'
                 .     '</td>'
                 // Page No Cell
                 .     '<td style="border:1px solid #000; text-align:center; padding:5px; vertical-align:middle;">'
                 .       '<div style="font-size:9px; text-align:left; margin-bottom:2px;">የገፅ ቁጥር<br>Page No.</div>'
                 .       '<div style="font-size:14px; font-weight:bold; margin-top:2px;">Page 1 of 1</div>'
                 .     '</td>'
                 .   '</tr>'
                 . '</table>';
        }

        /**
         * Replicates the complex external letter table with rowspans.
         */
        public static function externalTable(array $headers, array $rows): string
        {
            $html = '<table style="width:100%; border-collapse:collapse; border:1px solid #000; margin:20px 0; font-family:\'Nyala\', Arial, sans-serif; font-size:12px;">';
            
            // Header
            $html .= '<thead><tr style="background-color:#fff;">';
            foreach ($headers as $h) {
                $html .= '<th style="border:1px solid #000; padding:4px; text-align:center; font-weight:bold;">' . self::escape($h) . '</th>';
            }
            $html .= '</tr></thead><tbody>';

            // Rows
            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    // Allow raw HTML for rowspan/colspan support
                    if (str_starts_with((string)$cell, '<td')) {
                        $html .= $cell;
                    } else {
                        $html .= '<td style="border:1px solid #000; padding:4px; text-align:left;">' . self::escape($cell) . '</td>';
                    }
                }
                $html .= '</tr>';
            }

            return $html . '</tbody></table>';
        }

        /**
         * Generates the exact footers shown in the images.
         */
        public static function footer(bool $isInternal): string
        {
            if ($isInternal) {
                return '<div style="position:absolute; bottom:15mm; left:15mm; right:15mm;">'
                     .   '<div style="border-top:3px solid #000; padding-top:5px; text-align:center; font-family:\'Nyala\', Arial, sans-serif;">'
                     .     '<div style="font-size:12px; margin-bottom:2px;">ሰነዱ ከመፈረሙ በፊት ትክክለኛ መሆኑን ያረጋግጡ!</div>'
                     .     '<div style="font-size:11px; font-weight:bold;">PLEASE MAKE SURE THAT THIS IS THE CORRECT ISSUE BEFORE USE!!</div>'
                     .   '</div>'
                     . '</div>';
            }

            $address = get_setting('org_address', 'አዲስ አበባ - ኢትዮጵያ / Addis Ababa - Ethiopia');
            $pobox   = get_setting('org_pobox', '785');
            $phones  = get_setting('org_phones', '011-6-61-03-92'); // Assuming it's a comma separated string
            $email   = get_setting('org_email', 'misrakptc961@gmail.com');
            $iso     = APP_URL . '/assets/img/iso-logo.png';

            // The image shows a stacked list of phones on the left
            $phoneList = explode(',', $phones);
            $phoneHtml = '';
            foreach($phoneList as $p) {
                $phoneHtml .= '<div style="color:#DC2626; font-weight:bold; font-size:11px;">' . self::escape(trim($p)) . '</div>';
            }

            return '<div style="position:absolute; bottom:15mm; left:15mm; right:15mm;">'
                 .   '<div style="border-top:3px solid #000; padding-top:5px; display:flex; justify-content:space-between; align-items:flex-end; font-family:\'Nyala\', Arial, sans-serif;">'
                 .     '<div style="display:flex; gap:20px;">'
                 // Left Column (Phones)
                 .       '<div style="line-height:1.4;">' . $phoneHtml . '</div>'
                 // Middle Column (Address/Email)
                 .       '<div style="line-height:1.5;">'
                 .         '<div style="color:#4DA6E8; font-weight:bold; font-size:11px;">' . self::escape($address) . '</div>'
                 .         '<div style="color:#4DA6E8; font-weight:bold; font-size:11px;">ፒ.ኤ.ቁ: ' . self::escape($pobox) . '</div>'
                 .         '<div style="color:#4DA6E8; font-weight:bold; font-size:11px;">e-mail: ' . self::escape($email) . '</div>'
                 .       '</div>'
                 .     '</div>'
                 // Right Column (ISO Logo)
                 .     '<img src="' . self::escape($iso) . '" alt="ISO 21001:2018" style="width:110px;" onerror="this.style.display=\'none\'">'
                 .   '</div>'
                 // Bottom Reminder
                 .   '<div style="margin-top:5px; font-size:10px; font-family:\'Nyala\', Arial, sans-serif; color:#000;">'
                 .     'ማሳሰቢያ/ Reminder:- ከላይ የሚገኘውን ቁጥር ዋቢ አድርገው ሲጽፉ ይጠቀሙ::/Please provide Ref. No and subject matter always'
                 .   '</div>'
                 . '</div>';
        }

        public static function html(string $body, array $opts = []): string
        {
            $title      = (string)($opts['title'] ?? 'Document');
            $isInternal = (bool)($opts['is_internal'] ?? false);
            $autoPrint  = (bool)($opts['autoPrint'] ?? false);
            $css        = self::inlinePrintCss();
            $onLoad     = $autoPrint ? ' onload="window.print()"' : '';

            // Append footer to body
            $body .= self::footer($isInternal);

            return '<!DOCTYPE html><html lang="am"><head>'
                 .   '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
                 .   '<title>' . self::escape($title) . ' — MPC IEMS</title>'
                 .   '<link rel="icon" href="' . self::escape(get_favicon_url()) . '">'
                 .   '<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;500;600;700&display=swap" rel="stylesheet">'
                 .   '<style>' . $css . '</style>'
                 . '</head><body' . $onLoad . '>'
                 .   '<div class="page">' . $body . '</div>'
                 . '</body></html>';
        }

        public static function render(string $html): void
        {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('X-Content-Type-Options: nosniff');
            }
            echo $html;
            exit;
        }

        public static function download(string $html, string $filename, array $opts = []): void
        {
            $asAttachment = (bool)($opts['download'] ?? true);
            $filename     = self::sanitizeFilename($filename);
            $engine       = self::engine();

            if ($engine === 'mpdf') {
                try {
                    $mpdf = new \Mpdf\Mpdf(['format' => 'A4', 'margin_left' => 15, 'margin_right' => 15, 'margin_top' => 15, 'margin_bottom' => 15, 'autoScriptToLang' => true, 'autoLangToFont' => true]);
                    $mpdf->WriteHTML($html);
                    self::sendHeaders('application/pdf', $filename . '.pdf', $asAttachment);
                    $mpdf->Output($filename . '.pdf', $asAttachment ? 'D' : 'I');
                    exit;
                } catch (Throwable $e) { error_log('PdfExport mPDF failed: ' . $e->getMessage()); }
            }
            if ($engine === 'tcpdf') {
                try {
                    $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
                    $pdf->SetCreator('MPC IEMS'); $pdf->SetTitle($filename); $pdf->SetAutoPageBreak(true, 15);
                    $pdf->AddPage(); $pdf->writeHTML($html, true, false, true, false, '');
                    self::sendHeaders('application/pdf', $filename . '.pdf', $asAttachment);
                    $pdf->Output($filename . '.pdf', $asAttachment ? 'D' : 'I');
                    exit;
                } catch (Throwable $e) { error_log('PdfExport TCPDF failed: ' . $e->getMessage()); }
            }
            if ($engine === 'dompdf') {
                try {
                    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
                    $dompdf->loadHtml($html); $dompdf->setPaper('A4', 'portrait'); $dompdf->render();
                    self::sendHeaders('application/pdf', $filename . '.pdf', $asAttachment);
                    echo $dompdf->output(); exit;
                } catch (Throwable $e) { error_log('PdfExport Dompdf failed: ' . $e->getMessage()); }
            }

            self::sendHeaders('text/html', $filename . '.html', $asAttachment);
            echo $html;
            exit;
        }

        private static function sendHeaders(string $mime, string $filename, bool $attachment): void
        {
            if (headers_sent()) return;
            $disp = $attachment ? 'attachment' : 'inline';
            header('Content-Type: ' . $mime . '; charset=utf-8');
            header('Content-Disposition: ' . $disp . '; filename="' . $filename . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('X-Content-Type-Options: nosniff');
        }

        public static function escape($value): string
        {
            return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        public static function sanitizeFilename(string $name): string
        {
            $name = preg_replace('/\.(pdf|html?|php)$/i', '', $name);
            $name = preg_replace('/[\\\\\/\:\*\?"<>\|\x00-\x1F]/', '', (string)$name);
            $name = preg_replace('/\s+/', '_', trim((string)$name));
            if ($name === '' || $name === '.' || $name === '..') $name = 'document_' . date('Ymd_His');
            return $name;
        }

        private static function inlinePrintCss(): string
        {
            static $cache = null;
            if ($cache !== null) return $cache;

            $cssPath = __DIR__ . '/../assets/css/print.css';
            $baseCss = '';
            if (is_file($cssPath)) {
                $baseCss = (string)file_get_contents($cssPath);
                $baseCss = preg_replace('/@media\s+print\s*\{/', '', $baseCss);
                $baseCss = self::removeLastMatchingBrace($baseCss);
            }

            // Force exact A4 proportions and Nyala font
            $shell = <<<CSS
:root{--mpc-navy:#1B2A6B;--mpc-blue:#4DA6E8;--mpc-flame:#F59E0B;--mpc-red:#DC2626}
*{box-sizing:border-box}
html,body{margin:0;padding:0;font-family:'Nyala','Times New Roman',serif;color:#000;background:#525659}
.page{background:#fff;width:210mm;min-height:297mm;margin:20px auto;padding:15mm;box-shadow:0 0 10px rgba(0,0,0,.5);position:relative; box-sizing:border-box;}
@media print{
    body{background:#fff}
    .page{box-shadow:none;margin:0;width:210mm;height:297mm;padding:15mm;page-break-after:always;}
}
CSS;

            return $cache = $shell . "\n" . $baseCss;
        }

        private static function removeLastMatchingBrace(string $css): string
        {
            $pos = strrpos($css, '}');
            if ($pos === false) return $css;
            return substr($css, 0, $pos) . substr($css, $pos + 1);
        }
    }
}