$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Drawing

$outputDir = Join-Path $PSScriptRoot '..\assets'
if (-not (Test-Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

$pngPath = Join-Path $outputDir 'app-icon-mac-1024.png'
$size = 1024
$scale = 4
$bitmap = [System.Drawing.Bitmap]::new($size, $size)
$graphics = [System.Drawing.Graphics]::FromImage($bitmap)
$graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
$graphics.Clear([System.Drawing.Color]::FromArgb(14, 28, 49))

$shadowBrush = [System.Drawing.SolidBrush]::new([System.Drawing.Color]::FromArgb(28, 7, 16, 28))
$goldBrush = [System.Drawing.SolidBrush]::new([System.Drawing.Color]::FromArgb(207, 153, 35))
$whiteBrush = [System.Drawing.SolidBrush]::new([System.Drawing.Color]::White)
$mutedBrush = [System.Drawing.SolidBrush]::new([System.Drawing.Color]::FromArgb(173, 201, 219))
$innerBrush = [System.Drawing.SolidBrush]::new([System.Drawing.Color]::FromArgb(18, 43, 72))

$graphics.FillEllipse($shadowBrush, 18 * $scale, 140 * $scale, 220 * $scale, 86 * $scale)
$graphics.FillEllipse($goldBrush, 28 * $scale, 28 * $scale, 200 * $scale, 200 * $scale)
$graphics.FillEllipse($innerBrush, 42 * $scale, 42 * $scale, 172 * $scale, 172 * $scale)

$wingPen = [System.Drawing.Pen]::new([System.Drawing.Color]::FromArgb(214, 232, 246), [single](10 * $scale))
$wingPen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
$wingPen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
$graphics.DrawArc($wingPen, 70 * $scale, 78 * $scale, 118 * $scale, 62 * $scale, 208, 122)

$tailPen = [System.Drawing.Pen]::new([System.Drawing.Color]::FromArgb(214, 232, 246), [single](8 * $scale))
$tailPen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
$tailPen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
$graphics.DrawLine($tailPen, 106 * $scale, 132 * $scale, 86 * $scale, 146 * $scale)

$titleFont = [System.Drawing.Font]::new('Segoe UI Semibold', [single](54 * $scale), [System.Drawing.FontStyle]::Bold, [System.Drawing.GraphicsUnit]::Pixel)
$subtitleFont = [System.Drawing.Font]::new('Segoe UI Semibold', [single](18 * $scale), [System.Drawing.FontStyle]::Bold, [System.Drawing.GraphicsUnit]::Pixel)
$stringFormat = [System.Drawing.StringFormat]::new()
$stringFormat.Alignment = [System.Drawing.StringAlignment]::Center
$stringFormat.LineAlignment = [System.Drawing.StringAlignment]::Center

$graphics.DrawString('TA', $titleFont, $whiteBrush, ([System.Drawing.RectangleF]::new(0, 108 * $scale, $size, 54 * $scale)), $stringFormat)
$graphics.DrawString('OPS', $subtitleFont, $mutedBrush, ([System.Drawing.RectangleF]::new(0, 164 * $scale, $size, 24 * $scale)), $stringFormat)
$bitmap.Save($pngPath, [System.Drawing.Imaging.ImageFormat]::Png)

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

Write-Output "Generated macOS icon source: $pngPath"
