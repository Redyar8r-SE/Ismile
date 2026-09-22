# Split the two existing iSmile ink colours without redrawing the mark.
# Both outputs keep the original 1080 x 1155 logo viewport so they align exactly.
Add-Type -AssemblyName System.Drawing

$brandDir = Join-Path (Split-Path $PSScriptRoot -Parent) 'assets\brand'
$source = [System.Drawing.Bitmap]::new((Join-Path $brandDir 'ismile-logo.png'))
$format = [System.Drawing.Imaging.PixelFormat]::Format32bppArgb
$navy = [System.Drawing.Bitmap]::new(1080, 1155, $format)
$cyan = [System.Drawing.Bitmap]::new(1080, 1155, $format)

try {
  for ($y = 0; $y -lt 1155; $y++) {
    for ($x = 0; $x -lt 1080; $x++) {
      $pixel = $source.GetPixel($x, $y)
      if ($pixel.A -eq 0) { continue }

      # The source ink colours are navy (0, 78, 137) and cyan (33, 190, 206).
      # Nearest-colour matching keeps antialiased edge pixels with their colour.
      $navyDistance = [math]::Pow($pixel.R, 2) + [math]::Pow($pixel.G - 78, 2) + [math]::Pow($pixel.B - 137, 2)
      $cyanDistance = [math]::Pow($pixel.R - 33, 2) + [math]::Pow($pixel.G - 190, 2) + [math]::Pow($pixel.B - 206, 2)
      if ($navyDistance -le $cyanDistance) { $navy.SetPixel($x, $y, $pixel) }
      else { $cyan.SetPixel($x, $y, $pixel) }
    }
  }

  $navy.Save((Join-Path $brandDir 'ismile-mark-navy.png'), [System.Drawing.Imaging.ImageFormat]::Png)
  $cyan.Save((Join-Path $brandDir 'ismile-mark-cyan.png'), [System.Drawing.Imaging.ImageFormat]::Png)
}
finally {
  $source.Dispose()
  $navy.Dispose()
  $cyan.Dispose()
}
