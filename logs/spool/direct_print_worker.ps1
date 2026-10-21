param(
    [Parameter(Mandatory=$true)]
    [string]$JobJsonPath
)

$ErrorActionPreference = "Stop"

try {
    if (-not (Test-Path -LiteralPath $JobJsonPath)) {
        Write-Output '{"success":false,"message":"Job JSON file not found"}'
        exit 1
    }

    $jsonRaw = [System.IO.File]::ReadAllText($JobJsonPath, [System.Text.Encoding]::UTF8)
    $job = $jsonRaw | ConvertFrom-Json

    $printerName = [string]$job.printer_name
    $labelSize   = [string]$job.label_size
    $labels      = @($job.labels)

    if ($labels.Count -eq 0) {
        Write-Output '{"success":false,"message":"No labels to print"}'
        exit 1
    }

    Add-Type -AssemblyName System.Drawing

    $doc = New-Object System.Drawing.Printing.PrintDocument
    $doc.DocumentName = "MES_BoxLabels_" + $labelSize + "_" + $labels.Count + "p"
    $doc.PrintController = New-Object System.Drawing.Printing.StandardPrintController
    $doc.OriginAtMargins = $false

    # Match printer name (exact or case-insensitive)
    $doc.PrinterSettings.PrinterName = $printerName
    if (-not $doc.PrinterSettings.IsValid) {
        foreach ($installed in [System.Drawing.Printing.PrinterSettings]::InstalledPrinters) {
            if ($installed.ToString().Trim().ToLower() -eq $printerName.Trim().ToLower()) {
                $doc.PrinterSettings.PrinterName = $installed.ToString()
                break
            }
        }
    }

    if (-not $doc.PrinterSettings.IsValid) {
        $errMsg = "May in '" + $printerName + "' khong ton tai tren he thong Windows"
        $outObj = @{ success = $false; message = $errMsg; pages_printed = 0 }
        Write-Output ($outObj | ConvertTo-Json -Compress)
        exit 1
    }

    # Handle virtual file printers (Microsoft Print to PDF / XPS) without blocking on Save As dialog
    $pLower = $doc.PrinterSettings.PrinterName.ToLower()
    if ($pLower -match "print to pdf" -or $pLower -match "xps document writer") {
        $doc.PrinterSettings.PrintToFile = $true
        $doc.PrinterSettings.PrintFileName = [string]$job.pdf_fallback
    }

    $ps = $doc.DefaultPageSettings
    $ps.Margins = New-Object System.Drawing.Printing.Margins(0, 0, 0, 0)

    # Configure PaperSize & Landscape orientation (Width > Height)
    # Large label (80x100 / 100x80 / 110x80): ~394-433 x 300-315 hundredths of an inch (100-110mm wide x 76-80mm high)
    # Small label (65x30): 256 x 118 hundredths of an inch (65mm wide x 30mm high)
    $curW = $ps.PaperSize.Width
    $curH = $ps.PaperSize.Height

    if ($labelSize -eq "110x80") {
        $isCalibratedLarge = (($curW -ge 370 -and $curW -le 450 -and $curH -ge 275 -and $curH -le 345) -or ($curH -ge 370 -and $curH -le 450 -and $curW -ge 275 -and $curW -le 345))
        if (-not $isCalibratedLarge) {
            $ps.PaperSize = New-Object System.Drawing.Printing.PaperSize("MES_Label_100x80_Landscape", 394, 315)
        }
    } else {
        $isCalibratedSmall = (($curW -ge 230 -and $curW -le 280 -and $curH -ge 100 -and $curH -le 140) -or ($curH -ge 230 -and $curH -le 280 -and $curW -ge 100 -and $curW -le 140))
        if (-not $isCalibratedSmall) {
            $ps.PaperSize = New-Object System.Drawing.Printing.PaperSize("MES_Label_65x30_Landscape", 256, 118)
        }
    }

    # Guarantee LANDSCAPE when PaperSize is defined with Width < Height (e.g. 80mm x 100mm)
    if ($ps.PaperSize.Width -lt $ps.PaperSize.Height) {
        $ps.Landscape = $true
    } else {
        $ps.Landscape = $false
    }

    $script:pageIndex = 0

    $doc.add_PrintPage({
        param($sender, $e)

        $g = $e.Graphics
        $g.PageUnit = [System.Drawing.GraphicsUnit]::Millimeter

        $item = $labels[$script:pageIndex]

        $targetW = if ($labelSize -eq "110x80") { 104.0 } else { 65.0 }
        $targetH = if ($labelSize -eq "110x80") { 80.0 } else { 30.0 }

        $clipW = [double]$g.VisibleClipBounds.Width
        $clipH = [double]$g.VisibleClipBounds.Height

        # Adapt to thermal printhead printable area (e.g., 103.97mm x 76.2mm on Zebra 4-inch roll) while capping at targetW x targetH on A4/uncalibrated drivers
        $drawW = if ($clipW -gt 20.0 -and $clipW -lt $targetW) { $clipW } else { $targetW }
        $drawH = if ($clipH -gt 15.0 -and $clipH -lt $targetH) { $clipH } else { $targetH }

        # Render onto an offscreen 24-bpp RGB Bitmap at the printer's native DPI (e.g. 300 DPI).
        # This prevents ZDesigner monochrome GDI drivers from corrupting scaled PNG bitmaps into solid black boxes.
        $dpiX = if ($g.DpiX -gt 0) { [float]$g.DpiX } else { 300.0 }
        $dpiY = if ($g.DpiY -gt 0) { [float]$g.DpiY } else { 300.0 }
        $pxW  = [Math]::Max(120, [int][Math]::Round(($drawW / 25.4) * $dpiX))
        $pxH  = [Math]::Max(60,  [int][Math]::Round(($drawH / 25.4) * $dpiY))

        $canvas = New-Object System.Drawing.Bitmap($pxW, $pxH, [System.Drawing.Imaging.PixelFormat]::Format24bppRgb)
        $canvas.SetResolution($dpiX, $dpiY)

        $cg = [System.Drawing.Graphics]::FromImage($canvas)
        $cg.Clear([System.Drawing.Color]::White)
        $cg.PageUnit = [System.Drawing.GraphicsUnit]::Millimeter
        $cg.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
        $cg.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
        $cg.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::NearestNeighbor
        $cg.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::Half

        # Helper to auto-fit bold font within available width so text never clips or truncates with ellipsis
        $fitFont = {
            param([string]$txt, [float]$maxPt, [float]$minPt, [float]$maxW, [System.Drawing.FontStyle]$style = [System.Drawing.FontStyle]::Bold)
            if ([string]::IsNullOrEmpty($txt)) {
                return New-Object System.Drawing.Font("Arial", $maxPt, $style)
            }
            $safeMaxW = [Math]::Max(10.0, ($maxW - 1.2))
            $pt = $maxPt
            while ($pt -gt $minPt) {
                $fTest = New-Object System.Drawing.Font("Arial", $pt, $style)
                $sz = $cg.MeasureString($txt, $fTest)
                if ($sz.Width -le $safeMaxW) {
                    return $fTest
                }
                $fTest.Dispose()
                $pt = [float]($pt - 0.5)
            }
            return New-Object System.Drawing.Font("Arial", $minPt, $style)
        }

        # Decode Base64 QR Image (keep MemoryStream alive until after DrawImage completes)
        $qrImg = $null
        $ms = $null
        $b64 = [string]$item.qr_base64
        if ($b64 -match "^data:image\/[a-zA-Z0-9\+\-\.]+;base64,(.+)$") {
            $bytes = [System.Convert]::FromBase64String($Matches[1])
            $ms = New-Object System.IO.MemoryStream(,$bytes)
            $qrImg = [System.Drawing.Image]::FromStream($ms)
        }

        $line1 = [string]$item.material_name
        $weightVal = [string]$item.weight
        $line2 = if ([string]::IsNullOrWhiteSpace($weightVal)) { "$($item.qty) pcs" } else { "$($item.qty) pcs | $weightVal Kg" }
        $line3 = "||"
        $suppVal = [string]$item.supplier
        $line4 = if ([string]::IsNullOrWhiteSpace($suppVal)) { "$($item.lot_no)" } else { "$($item.lot_no) | $suppVal" }
        $line5 = [string]$item.box_no

        $sf = New-Object System.Drawing.StringFormat
        $sf.FormatFlags = [System.Drawing.StringFormatFlags]::NoWrap
        $sf.Trimming = [System.Drawing.StringTrimming]::None
        $sf.Alignment = [System.Drawing.StringAlignment]::Near
        $sf.LineAlignment = [System.Drawing.StringAlignment]::Center

        if ($labelSize -eq "110x80") {
            # =========================================================
            # LAYOUT TEM LON 80 x 100 / 100 x 80 / 110 x 80 MM (LANDSCAPE)
            # Full-area utilization: Maximized 58mm QR Code & Full-Width Bold Typography
            # =========================================================
            $padX = 1.8
            $padY = 2.0
            $qrSize = [Math]::Min(58.0, ($drawH - ($padY * 2.0)))
            $qrX = $padX
            $qrY = ($drawH - $qrSize) / 2.0

            if ($qrImg -ne $null) {
                $cg.DrawImage($qrImg, [System.Drawing.RectangleF]::new([float]$qrX, [float]$qrY, [float]$qrSize, [float]$qrSize))
                $qrImg.Dispose()
            }
            if ($ms -ne $null) {
                $ms.Dispose()
            }

            $gapX = 2.0
            $textX = $qrX + $qrSize + $gapX
            $textW = [Math]::Max(32.0, ($drawW - $textX - $padX))

            $usableH = $drawH - ($padY * 2.0)
            $rowH = $usableH / 5.0

            $f1 = & $fitFont $line1 18.0 8.5 $textW ([System.Drawing.FontStyle]::Bold)
            $f2 = & $fitFont $line2 16.0 9.0 $textW ([System.Drawing.FontStyle]::Bold)
            $f3 = New-Object System.Drawing.Font("Arial", 15.0, [System.Drawing.FontStyle]::Bold)
            $f4 = & $fitFont $line4 14.5 8.5 $textW ([System.Drawing.FontStyle]::Bold)
            $f5 = & $fitFont $line5 18.0 9.5 $textW ([System.Drawing.FontStyle]::Bold)

            $cg.DrawString($line1, $f1, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 0.0)), [float]$textW, [float]$rowH), $sf)
            $cg.DrawString($line2, $f2, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 1.0)), [float]$textW, [float]$rowH), $sf)
            $cg.DrawString($line3, $f3, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 2.0)), [float]$textW, [float]$rowH), $sf)

            # Allow 2-line wrap on Line 4 (Lot | Supplier) if long so full supplier info is visible
            $sfWrap = New-Object System.Drawing.StringFormat
            $sfWrap.Trimming = [System.Drawing.StringTrimming]::EllipsisCharacter
            $sfWrap.Alignment = [System.Drawing.StringAlignment]::Near
            $sfWrap.LineAlignment = [System.Drawing.StringAlignment]::Center
            $cg.DrawString($line4, $f4, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 3.0)), [float]$textW, [float]$rowH), $sfWrap)
            $sfWrap.Dispose()

            $lineY = [float]($padY + ($rowH * 4.0))
            $penDiv = New-Object System.Drawing.Pen([System.Drawing.Color]::Black, 0.75)
            $cg.DrawLine($penDiv, [float]$textX, $lineY, [float]($textX + $textW - 0.8), $lineY)
            $penDiv.Dispose()

            $cg.DrawString($line5, $f5, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($lineY + 0.8), [float]$textW, [float]($rowH - 0.8)), $sf)

            $f1.Dispose(); $f2.Dispose(); $f3.Dispose(); $f4.Dispose(); $f5.Dispose()
        } else {
            # =========================================================
            # LAYOUT TEM 65 x 30 MM (LANDSCAPE: RONG 65MM x CAO 30MM)
            # =========================================================
            $padX = 1.5
            $padY = 1.2
            $qrSize = [Math]::Min(25.5, ($drawH - ($padY * 2.0)))
            $qrX = $padX
            $qrY = ($drawH - $qrSize) / 2.0

            if ($qrImg -ne $null) {
                $cg.DrawImage($qrImg, [System.Drawing.RectangleF]::new([float]$qrX, [float]$qrY, [float]$qrSize, [float]$qrSize))
                $qrImg.Dispose()
            }
            if ($ms -ne $null) {
                $ms.Dispose()
            }

            $gapX = 1.6
            $textX = $qrX + $qrSize + $gapX
            $textW = [Math]::Max(20.0, ($drawW - $textX - $padX))

            $usableH = $drawH - ($padY * 2.0)
            $rowH = $usableH / 5.0

            $f1 = & $fitFont $line1 9.0 6.5 $textW ([System.Drawing.FontStyle]::Bold)
            $f2 = & $fitFont $line2 8.0 6.0 $textW ([System.Drawing.FontStyle]::Bold)
            $f3 = New-Object System.Drawing.Font("Arial", 7.2, [System.Drawing.FontStyle]::Bold)
            $f4 = & $fitFont $line4 7.2 5.5 $textW ([System.Drawing.FontStyle]::Bold)
            $f5 = & $fitFont $line5 9.0 6.5 $textW ([System.Drawing.FontStyle]::Bold)

            $cg.DrawString($line1, $f1, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 0.0)), [float]$textW, [float]$rowH), $sf)
            $cg.DrawString($line2, $f2, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 1.0)), [float]$textW, [float]$rowH), $sf)
            $cg.DrawString($line3, $f3, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 2.0)), [float]$textW, [float]$rowH), $sf)
            $cg.DrawString($line4, $f4, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($padY + ($rowH * 3.0)), [float]$textW, [float]$rowH), $sf)

            $lineY = [float]($padY + ($rowH * 4.0))
            $penDiv = New-Object System.Drawing.Pen([System.Drawing.Color]::Black, 0.4)
            $cg.DrawLine($penDiv, [float]$textX, $lineY, [float]($textX + $textW - 0.5), $lineY)
            $penDiv.Dispose()

            $cg.DrawString($line5, $f5, [System.Drawing.Brushes]::Black, [System.Drawing.RectangleF]::new([float]$textX, [float]($lineY + 0.4), [float]$textW, [float]($rowH - 0.4)), $sf)

            $f1.Dispose(); $f2.Dispose(); $f3.Dispose(); $f4.Dispose(); $f5.Dispose()
        }

        $sf.Dispose()
        $cg.Dispose()

        # Blit the 300-DPI rendered bitmap 1:1 in Pixel units onto the printer Graphics surface
        $g.PageUnit = [System.Drawing.GraphicsUnit]::Pixel
        $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::NearestNeighbor
        $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::Half
        $g.DrawImage($canvas, 0, 0, $pxW, $pxH)
        $canvas.Dispose()

        # Strict pagination: 1 label = 1 page, N labels = N pages, never any blank page
        $script:pageIndex++
        if ($script:pageIndex -lt $labels.Count) {
            $e.HasMorePages = $true
        } else {
            $e.HasMorePages = $false
        }
    })

    $doc.Print()
    $doc.Dispose()

    $res = @{
        success       = $true
        message       = "Da gui lenh in truc tiep (" + $labels.Count + " tem, kho " + $labelSize + " Landscape) den may in: " + $printerName
        pages_printed = $labels.Count
    }
    Write-Output ($res | ConvertTo-Json -Compress)
    exit 0
} catch {
    $errRes = @{
        success       = $false
        message       = "Loi Windows Print Spooler: " + $_.Exception.Message
        pages_printed = 0
    }
    Write-Output ($errRes | ConvertTo-Json -Compress)
    exit 1
}