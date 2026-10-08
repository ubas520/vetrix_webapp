Add-Type -AssemblyName System.Drawing
$diagramFiles = Get-ChildItem -LiteralPath $PSScriptRoot -Filter '*.drawing.json'
foreach ($diagramFile in $diagramFiles) {
    $diagram = Get-Content -LiteralPath $diagramFile.FullName -Raw -Encoding UTF8 | ConvertFrom-Json
    $bitmap = New-Object System.Drawing.Bitmap([int]$diagram.width, [int]$diagram.height)
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    $graphics.Clear([System.Drawing.Color]::White)
    $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
    foreach ($shape in $diagram.shapes) {
        if ($shape.type -eq 'rect') {
            $brush = New-Object System.Drawing.SolidBrush([System.Drawing.ColorTranslator]::FromHtml($shape.fill))
            $pen = New-Object System.Drawing.Pen([System.Drawing.ColorTranslator]::FromHtml($shape.stroke), 1.5)
            $drawingPath = New-Object System.Drawing.Drawing2D.GraphicsPath
            $x=[single]$shape.x; $y=[single]$shape.y; $w=[single]$shape.w; $h=[single]$shape.h; $d=[single]($shape.radius*2)
            if ($d -gt 0) {
                $drawingPath.AddArc($x,$y,$d,$d,180,90)
                $drawingPath.AddArc(($x+$w-$d),$y,$d,$d,270,90)
                $drawingPath.AddArc(($x+$w-$d),($y+$h-$d),$d,$d,0,90)
                $drawingPath.AddArc($x,($y+$h-$d),$d,$d,90,90)
                $drawingPath.CloseFigure()
            } else { $drawingPath.AddRectangle([System.Drawing.RectangleF]::new($x,$y,$w,$h)) }
            $graphics.FillPath($brush,$drawingPath); $graphics.DrawPath($pen,$drawingPath)
            $brush.Dispose(); $pen.Dispose(); $drawingPath.Dispose()
        } elseif ($shape.type -eq 'line') {
            $pen=New-Object System.Drawing.Pen([System.Drawing.ColorTranslator]::FromHtml($shape.stroke),1.7)
            if ($shape.arrow) { $cap=New-Object System.Drawing.Drawing2D.AdjustableArrowCap(5,6); $pen.CustomEndCap=$cap }
            $graphics.DrawLine($pen,[single]$shape.x1,[single]$shape.y1,[single]$shape.x2,[single]$shape.y2)
            $pen.Dispose(); if ($shape.arrow) { $cap.Dispose() }
        } else {
            $style=if($shape.bold){[System.Drawing.FontStyle]::Bold}else{[System.Drawing.FontStyle]::Regular}
            $font=New-Object System.Drawing.Font('Segoe UI',[single]$shape.size,$style,[System.Drawing.GraphicsUnit]::Pixel)
            $brush=New-Object System.Drawing.SolidBrush([System.Drawing.ColorTranslator]::FromHtml($shape.fill))
            $format=New-Object System.Drawing.StringFormat
            $format.Alignment=if($shape.align -eq 'center'){[System.Drawing.StringAlignment]::Center}else{[System.Drawing.StringAlignment]::Near}
            $format.LineAlignment=[System.Drawing.StringAlignment]::Center
            $graphics.DrawString([string]$shape.text,$font,$brush,[System.Drawing.PointF]::new([single]$shape.x,[single]$shape.y),$format)
            $font.Dispose();$brush.Dispose();$format.Dispose()
        }
    }
    $destination=Join-Path $PSScriptRoot ($diagramFile.Name.Replace('.drawing.json','.png'))
    $bitmap.Save($destination,[System.Drawing.Imaging.ImageFormat]::Png)
    $graphics.Dispose();$bitmap.Dispose()
    Write-Output $destination
}
