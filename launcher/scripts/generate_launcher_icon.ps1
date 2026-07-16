Add-Type -AssemblyName System.Drawing

$outputDir = Join-Path $PSScriptRoot '..\assets'
if (-not (Test-Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

$pngPath = Join-Path $outputDir 'app-icon-256.png'
$icoPath = Join-Path $outputDir 'app-icon.ico'

$size = 256
$bitmap = New-Object System.Drawing.Bitmap $size, $size
$graphics = [System.Drawing.Graphics]::FromImage($bitmap)
$graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit

$background = New-Object System.Drawing.Rectangle 0, 0, $size, $size
$graphics.Clear([System.Drawing.Color]::FromArgb(14, 28, 49))

$shadowBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(28, 7, 16, 28))
$graphics.FillEllipse($shadowBrush, 18, 140, 220, 86)

$goldBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(207, 153, 35))
$whiteBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::White)
$mutedBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(173, 201, 219))

$circleRect = New-Object System.Drawing.Rectangle 28, 28, 200, 200
$graphics.FillEllipse($goldBrush, $circleRect)

$innerRect = New-Object System.Drawing.Rectangle 42, 42, 172, 172
$innerBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(18, 43, 72))
$graphics.FillEllipse($innerBrush, $innerRect)

$wingPen = New-Object System.Drawing.Pen ([System.Drawing.Color]::FromArgb(214, 232, 246), 10)
$wingPen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
$wingPen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
$graphics.DrawArc($wingPen, 70, 78, 118, 62, 208, 122)

$tailPen = New-Object System.Drawing.Pen ([System.Drawing.Color]::FromArgb(214, 232, 246), 8)
$tailPen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
$tailPen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
$graphics.DrawLine($tailPen, 106, 132, 86, 146)

$titleFont = New-Object System.Drawing.Font('Segoe UI Semibold', 54, [System.Drawing.FontStyle]::Bold, [System.Drawing.GraphicsUnit]::Pixel)
$subtitleFont = New-Object System.Drawing.Font('Segoe UI Semibold', 18, [System.Drawing.FontStyle]::Bold, [System.Drawing.GraphicsUnit]::Pixel)

$stringFormat = New-Object System.Drawing.StringFormat
$stringFormat.Alignment = [System.Drawing.StringAlignment]::Center
$stringFormat.LineAlignment = [System.Drawing.StringAlignment]::Center

$graphics.DrawString('TA', $titleFont, $whiteBrush, ([System.Drawing.RectangleF]::new(0, 108, $size, 54)), $stringFormat)
$graphics.DrawString('OPS', $subtitleFont, $mutedBrush, ([System.Drawing.RectangleF]::new(0, 164, $size, 24)), $stringFormat)

$bitmap.Save($pngPath, [System.Drawing.Imaging.ImageFormat]::Png)

$pngBytes = [System.IO.File]::ReadAllBytes($pngPath)
$memoryStream = New-Object System.IO.MemoryStream
$writer = New-Object System.IO.BinaryWriter $memoryStream

$writer.Write([UInt16]0)
$writer.Write([UInt16]1)
$writer.Write([UInt16]1)
$writer.Write([Byte]0)
$writer.Write([Byte]0)
$writer.Write([Byte]0)
$writer.Write([Byte]0)
$writer.Write([UInt16]1)
$writer.Write([UInt16]32)
$writer.Write([UInt32]$pngBytes.Length)
$writer.Write([UInt32]22)
$writer.Write($pngBytes)
$writer.Flush()

[System.IO.File]::WriteAllBytes($icoPath, $memoryStream.ToArray())

$writer.Dispose()
$memoryStream.Dispose()
$graphics.Dispose()
$bitmap.Dispose()
$shadowBrush.Dispose()
$goldBrush.Dispose()
$whiteBrush.Dispose()
$mutedBrush.Dispose()
$innerBrush.Dispose()
$wingPen.Dispose()
$tailPen.Dispose()
$titleFont.Dispose()
$subtitleFont.Dispose()
$stringFormat.Dispose()
