<?php

namespace Weserv\Images\Manipulators;

use Jcupitt\Vips\Access;
use Jcupitt\Vips\BlendMode;
use Jcupitt\Vips\Image;
use Weserv\Images\Manipulators\Helpers\Utils;

/**
 * @property string $shape
 * @property string $circle
 * @property string $strim
 */
class Shape extends BaseManipulator
{
    /**
     * Perform shape image manipulation.
     *
     * @param Image $image The source image.
     *
     * @throws \Jcupitt\Vips\Exception
     *
     * @return Image The manipulated image.
     */
    public function run(Image $image): Image
    {
        $shape = $this->getShape();

        if ($shape !== null) {
            $width = $image->width;
            $height = $image->height;

            [$path, $xMin, $yMin, $maskWidth, $maskHeight] = $this->getSVGShape($width, $height, $shape);

            $preserveAspectRatio = $shape === 'ellipse' ? 'none' : 'xMidYMid meet';
            $svg = '<?xml version=\'1.0\' encoding=\'UTF-8\' standalone=\'no\'?>';
            $svg .= "<svg xmlns='http://www.w3.org/2000/svg' version='1.1' width='$width' height='$height'";
            $svg .= " viewBox='$xMin $yMin $maskWidth $maskHeight'";
            $svg .= " shape-rendering='geometricPrecision' preserveAspectRatio='$preserveAspectRatio'>";
            $svg .= $path;
            $svg .= '</svg>';

            $mask = Image::newFromBuffer($svg, '', [
                'access' => Access::SEQUENTIAL
            ]);

            // Cutout via dest-in
            $image = $image->composite($mask, [BlendMode::DEST_IN]);

            // Crop the image to the mask dimensions;
            // if strim is defined and if it's not a ellipse
            if (isset($this->strim) && $shape !== 'ellipse') {
                [$left, $top, $trimWidth, $trimHeight] = $this->resolveShapeTrim(
                    $width,
                    $height,
                    $maskWidth,
                    $maskHeight
                );

                // If the trim dimensions is less than the image dimensions
                if ($trimWidth < $width || $trimHeight < $height) {
                    $image = $image->extract_area($left, $top, $trimWidth, $trimHeight);
                }
            }
        }

        return $image;
    }

    /**
     * Resolve shape
     *
     * @return string|null The resolved shape.
     */
    public function getShape(): ?string
    {
        if ($this->shape === 'circle' ||
            $this->shape === 'ellipse' ||
            $this->shape === 'hexagon' ||
            $this->shape === 'pentagon' ||
            $this->shape === 'pentagon-180' ||
            $this->shape === 'square' ||
            $this->shape === 'star' ||
            $this->shape === 'heart' ||
            $this->shape === 'triangle' ||
            $this->shape === 'triangle-180'
        ) {
            return $this->shape;
        }

        // Deprecated use shape=circle instead
        if (isset($this->circle)) {
            return 'circle';
        }

        return null;
    }

    /**
     * Get the SVG shape
     *
     * @param int $width
     * @param int $height
     * @param string $shape
     *
     * @return array [
     *      *SVG path*,
     *      *Left edge of mask*,
     *      *Top edge of mask*,
     *      *Mask width*,
     *      *Mask height*
     * ]
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function getSVGShape(int $width, int $height, string $shape): array
    {
        $min = min($width, $height);
        $outerRadius = $min / 2;
        $midX = $width / 2;
        $midY = $height / 2;

        if ($shape === 'ellipse') {
            // Ellipse
            return ["<ellipse cx='$midX' cy='$midY' rx='$midX' ry='$midY'/>", 0, 0, $width, $height];
        }

        if ($shape === 'circle') {
            // Circle
            $xMin = $midX - $outerRadius;
            $yMin = $midY - $outerRadius;
            return ["<circle r='$outerRadius' cx='$midX' cy='$midY'/>", $xMin, $yMin, $min, $min];
        }

        if ($shape === 'heart') {
            // Heart
            return $this->getSVGHeart($outerRadius, $outerRadius);
        }

        // 'inner' radius of the polygon/star
        $innerRadius = $outerRadius;

        // Initial angle (clockwise). By default, stars and polygons are 'pointing' up.
        $initialAngle = 0.0;

        // Number of points (or number of sides for polygons)
        $points = 0;

        switch ($shape) {
            case 'hexagon':
                // Hexagon
                $points = 6;
                break;
            case 'pentagon':
                // Pentagon
                $points = 5;
                break;
            case 'pentagon-180':
                // Pentagon tilted upside down
                $points = 5;
                $initialAngle = M_PI;
                break;
            case 'star':
                // 5 point star
                $points = 5 * 2;
                $innerRadius *= .382;
                break;
            case 'square':
                // Square tilted 45 degrees
                $points = 4;
                break;
            case 'triangle':
                // Triangle
                $points = 3;
                break;
            case 'triangle-180':
                // Triangle upside down
                $points = 3;
                $initialAngle = M_PI;
                break;
        }

        return $this->getSVGMask($midX, $midY, [$points, $outerRadius, $innerRadius, $initialAngle]);
    }

    /**
     * Formula from http://mathworld.wolfram.com/HeartCurve.html
     *
     * @param float $midX midX
     * @param float $midY midY
     *
     * @return array [
     *      *SVG path*,
     *      *Left edge of mask*,
     *      *Top edge of mask*,
     *      *Mask width*,
     *      *Mask height*
     * ]
     */
    public function getSVGHeart(float $midX, float $midY): array
    {
        $path = 'M';
        $xArr = [];
        $yArr = [];
        for ($t = -M_PI; $t <= M_PI; $t += 0.02) {
            $xPt = 16 * (sin($t) ** 3);
            $yPt = 13 * cos($t) - 5 * cos(2 * $t) - 2 * cos(3 * $t) - cos(4 * $t);

            $x = round($midX + $xPt * $midX);
            $y = round($midY - $yPt * $midY);
            $xArr[] = $x;
            $yArr[] = $y;
            $path .= "$x $y L";
        }
        $xMin = min($xArr);
        $yMin = min($yArr);

        return ["<path d='$path Z'/>", $xMin, $yMin, max($xArr) - $xMin, max($yArr) - $yMin];
    }

    /**
     * Inspired by this JSFiddle: https://jsfiddle.net/tohan/8vwjn4cx/
     * modified to support SVG paths
     *
     * @param float $midX midX
     * @param float $midY midY
     * @param array $parameters mask parameters
     *
     * @return array [
     *      *SVG path*,
     *      *Left edge of mask*,
     *      *Top edge of mask*,
     *      *Mask width*,
     *      *Mask height*
     * ]
     */
    private function getSVGMask(float $midX, float $midY, array $parameters): array
    {
        /**
         * @var int $points Number of points (or number of sides for polygons)
         * @var float $outerRadius 'outer' radius of the star
         * @var float $innerRadius 'inner' radius of the star (if equal to outerRadius, a polygon is drawn)
         * @var float $initialAngle Initial angle (clockwise). By default, stars and polygons are 'pointing' up.
         */
        [$points, $outerRadius, $innerRadius, $initialAngle] = $parameters;

        $path = '';
        $xArr = [];
        $yArr = [];
        for ($i = 0; $i <= $points; $i++) {
            $angle = $i * 2 * M_PI / $points - M_PI / 2 + $initialAngle;
            $radius = $i % 2 === 0 ? $outerRadius : $innerRadius;
            if ($i === 0) {
                $path = 'M';
                // If an odd number of points, add an additional point at the top of the polygon
                // -- this will shift the calculated center point of the shape so that the center point
                // of the polygon is at x,y (otherwise the center is mis-located)
                if ($points % 2 === 1) {
                    $path .= "0 $radius M";
                }
            } else {
                $path .= ' L';
            }
            $x = round($midX + $radius * cos($angle));
            $y = round($midY + $radius * sin($angle));
            $xArr[] = $x;
            $yArr[] = $y;
            $path .= "$x $y";
        }
        $xMin = min($xArr);
        $yMin = min($yArr);

        return ["<path d='$path Z'/>", $xMin, $yMin, max($xArr) - $xMin, max($yArr) - $yMin];
    }

    /**
     * Calculate the area to extract
     *
     * @param int $width
     * @param int $height
     * @param int $maskWidth
     * @param int $maskHeight
     *
     * @return array
     */
    public function resolveShapeTrim(int $width, int $height, int $maskWidth, int $maskHeight): array
    {
        $xScale = (float)($width / $maskWidth);
        $yScale = (float)($height / $maskHeight);
        $scale = min($xScale, $yScale);

        $trimWidth = $maskWidth * $scale;
        $trimHeight = $maskHeight * $scale;
        $left = (int)round(($width - $trimWidth) / 2);
        $top = (int)round(($height - $trimHeight) / 2);

        return [$left, $top, (int)round($trimWidth), (int)round($trimHeight)];
    }
}
