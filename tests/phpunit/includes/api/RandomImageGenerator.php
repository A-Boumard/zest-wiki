<?php
/**
 * RandomImageGenerator -- does what it says on the tin.
 * Requires Imagick, the ImageMagick library for PHP, or the command line
 * equivalent (usually 'convert').
 *
 * Because MediaWiki tests the uniqueness of media upload content, and
 * filenames, it is sometimes useful to generate files that are guaranteed (or
 * at least very likely) to be unique in both those ways. This generates a
 * number of filenames with random names and random content (colored triangles).
 *
 * It is also useful to have fresh content because our tests currently run in a
 * "destructive" mode, and don't create a fresh new wiki for each test run.
 * Consequently, if we just had a few static files we kept re-uploading, we'd
 * get lots of warnings about matching content or filenames, and even if we
 * deleted those files, we'd get warnings about archived files.
 *
 * This can also be used with a cronjob to generate random files all the time.
 * I use it to have a constant, never ending supply when I'm testing
 * interactively.
 *
 * @file
 * @author Neil Kandalgaonkar <neilk@wikimedia.org>
 */

use MediaWiki\Shell\Shell;

/**
 * RandomImageGenerator: does what it says on the tin.
 * Can fetch a random image, or also write a number of them to disk with random filenames.
 */
class RandomImageGenerator {
	private $minWidth = 400;
	private $maxWidth = 800;
	private $minHeight = 400;
	private $maxHeight = 800;
	private $shapesToDraw = 5;

	/**
	 * Orientations: 0th row, 0th column, Exif orientation code, rotation 2x2
	 * matrix that is opposite of orientation. N.b. we do not handle the
	 * 'flipped' orientations, which is why there is no entry for 2, 4, 5, or 7.
	 * Those seem to be rare in real images anyway (we also would need a
	 * non-symmetric shape for the images to test those, like a letter F).
	 */
	private static $orientations = [
		[
			'0thRow' => 'top',
			'0thCol' => 'left',
			'exifCode' => 1,
			'counterRotation' => [ [ 1, 0 ], [ 0, 1 ] ]
		],
		[
			'0thRow' => 'bottom',
			'0thCol' => 'right',
			'exifCode' => 3,
			'counterRotation' => [ [ -1, 0 ], [ 0, -1 ] ]
		],
		[
			'0thRow' => 'right',
			'0thCol' => 'top',
			'exifCode' => 6,
			'counterRotation' => [ [ 0, 1 ], [ 1, 0 ] ]
		],
		[
			'0thRow' => 'left',
			'0thCol' => 'bottom',
			'exifCode' => 8,
			'counterRotation' => [ [ 0, -1 ], [ -1, 0 ] ]
		]
	];

	public function __construct( $options = [] ) {
		foreach ( [ 'minWidth', 'minHeight',
			'maxWidth', 'maxHeight', 'shapesToDraw' ] as $property
		) {
			if ( isset( $options[$property] ) ) {
				$this->$property = $options[$property];
			}
		}
	}

	/**
	 * Writes random images with random filenames to disk in the directory you
	 * specify, or current working directory.
	 *
	 * @param int $number Number of filenames to write
	 * @param string $format Optional, must be understood by ImageMagick, such as 'jpg' or 'gif'
	 * @param string|null $dir Directory, optional (will default to current working directory)
	 * @return array Filenames we just wrote
	 */
	public function writeImages( $number, $format = 'jpg', $dir = null ) {
		$filenames = $this->getRandomFilenames( $number, $format, $dir );
		$imageWriteMethod = $this->getImageWriteMethod( $format );
		foreach ( $filenames as $filename ) {
			$this->{$imageWriteMethod}( $this->getImageSpec(), $format, $filename );
		}

		return $filenames;
	}

	/**
	 * Figure out how we write images. This is a factor of both format and the local system
	 *
	 * @param string $format (a typical extension like 'svg', 'jpg', etc.)
	 *
	 * @throws Exception
	 * @return string
	 */
	public function getImageWriteMethod( $format ) {
		global $wgUseImageMagick, $wgImageMagickConvertCommand;
		if ( $format === 'svg' ) {
			return 'writeSvg';
		} else {
			// figure out how to write images
			global $wgExiv2Command;
			if ( class_exists( Imagick::class ) && $wgExiv2Command && is_executable( $wgExiv2Command ) ) {
				return 'writeImageWithApi';
			} elseif ( $wgUseImageMagick
				&& $wgImageMagickConvertCommand
				&& is_executable( $wgImageMagickConvertCommand )
			) {
				return 'writeImageWithCommandLine';
			}
		}
		throw new Exception( "RandomImageGenerator: could not find a suitable "
			. "method to write images in '$format' format" );
	}

	/**
	 * Return a number of randomly-generated filenames.
	 *
	 * Each filename uses follows the pattern "hex_timestamp_1.jpg".
	 *
	 * @param int $number Number of filenames to generate
	 * @param string $extension Optional, defaults to 'jpg'
	 * @param string|null $dir Optional, defaults to current working directory
	 * @return string[]
	 */
	private function getRandomFilenames( $number, $extension = 'jpg', $dir = null ) {
		$dir ??= getcwd();
		$filenames = [];
		$prefix = wfRandomString( 3 ) . '_' . gmdate( 'YmdHis' ) . '_';
		foreach ( range( 1, $number ) as $offset ) {
			$filename = $prefix . $offset;
			if ( $extension !== null ) {
				$filename .= '.' . $extension;
			}
			$filenames[] = "$dir/$filename";
		}

		return $filenames;
	}

	/**
	 * Generate data representing an image of random size (within limits),
	 * consisting of randomly colored and sized upward pointing triangles
	 * against a random background color. (This data is used in the
	 * writeImage* methods).
	 *
	 * @return array
	 */
	public function getImageSpec() {
		$spec = [];

		$spec['width'] = mt_rand( $this->minWidth, $this->maxWidth );
		$spec['height'] = mt_rand( $this->minHeight, $this->maxHeight );
		$spec['fill'] = $this->getRandomColor();

		$diagonalLength = sqrt( $spec['width'] ** 2 + $spec['height'] ** 2 );

		$draws = [];
		for ( $i = 0; $i <= $this->shapesToDraw; $i++ ) {
			$radius = mt_rand( 0, (int)( $diagonalLength / 4 ) );
			if ( $radius == 0 ) {
				continue;
			}
			$originX = mt_rand( -1 * $radius, $spec['width'] + $radius );
			$originY = mt_rand( -1 * $radius, $spec['height'] + $radius );
			$angle = mt_rand() / mt_getrandmax() * M_PI / 2;
			$legDeltaX = round( $radius * sin( $angle ) );
			$legDeltaY = round( $radius * cos( $angle ) );

			$draw = [];
			$draw['fill'] = $this->getRandomColor();
			$draw['shape'] = [
				[ 'x' => $originX, 'y' => $originY - $radius ],
				[ 'x' => $originX + $legDeltaX, 'y' => $originY + $legDeltaY ],
				[ 'x' => $originX - $legDeltaX, 'y' => $originY + $legDeltaY ],
				[ 'x' => $originX, 'y' => $originY - $radius ]
			];
			$draws[] = $draw;
		}

		$spec['draws'] = $draws;

		return $spec;
	}

	/**
	 * Given [ [ 'x' => 10, 'y' => 20 ], [ 'x' => 30, y=> 5 ] ]
	 * returns "10,20 30,5"
	 * Useful for SVG and imagemagick command line arguments
	 * @param array $shape Array of arrays, each array containing x & y keys mapped to numeric values
	 * @return string
	 */
	public static function shapePointsToString( $shape ) {
		$points = [];
		foreach ( $shape as $point ) {
			$points[] = $point['x'] . ',' . $point['y'];
		}

		return implode( " ", $points );
	}

	/**
	 * Based on image specification, write a very simple SVG file to disk.
	 * Ignores the background spec because transparency is cool. :)
	 *
	 * @param array $spec Spec describing background and shapes to draw
	 * @param string $format File format to write (which is obviously always svg here)
	 * @param string $filename Filename to write to
	 *
	 * @throws Exception
	 */
	public function writeSvg( $spec, $format, $filename ) {
		$svg = new SimpleXmlElement( '<svg/>' );
		$svg->addAttribute( 'xmlns', 'http://www.w3.org/2000/svg' );
		$svg->addAttribute( 'version', '1.1' );
		$svg->addAttribute( 'width', $spec['width'] );
		$svg->addAttribute( 'height', $spec['height'] );
		$g = $svg->addChild( 'g' );
		foreach ( $spec['draws'] as $drawSpec ) {
			$shape = $g->addChild( 'polygon' );
			$shape->addAttribute( 'fill', $drawSpec['fill'] );
			$shape->addAttribute( 'points', self::shapePointsToString( $drawSpec['shape'] ) );
		}

		$fh = fopen( $filename, 'w' );
		if ( !$fh ) {
			throw new UnexpectedValueException( "couldn't open $filename for writing" );
		}
		fwrite( $fh, $svg->asXML() );
		if ( !fclose( $fh ) ) {
			throw new UnexpectedValueException( "couldn't close $filename" );
		}
	}

	/**
	 * Based on an image specification, write such an image to disk, using Imagick PHP extension
	 * @param array $spec Spec describing background and circles to draw
	 * @param string $format File format to write
	 * @param string $filename Filename to write to
	 */
	public function writeImageWithApi( $spec, $format, $filename ) {
		// this is a hack because I can't get setImageOrientation() to work. See below.
		global $wgExiv2Command;

		$image = new Imagick();
		/**
		 * If the format is 'jpg', will also add a random orientation -- the
		 * image will be drawn rotated with triangle points facing in some
		 * direction (0, 90, 180 or 270 degrees) and a countering rotation
		 * should turn the triangle points upward again.
		 */
		$orientation = self::$orientations[0]; // default is normal orientation
		if ( $format == 'jpg' ) {
			$orientation = self::$orientations[array_rand( self::$orientations )];
			$spec = self::rotateImageSpec( $spec, $orientation['counterRotation'] );
		}

		$image->newImage( $spec['width'], $spec['height'], new ImagickPixel( $spec['fill'] ) );

		foreach ( $spec['draws'] as $drawSpec ) {
			$draw = new ImagickDraw();
			$draw->setFillColor( $drawSpec['fill'] );
			$draw->polygon( $drawSpec['shape'] );
			$image->drawImage( $draw );
		}

		$image->setImageFormat( $format );

		// this doesn't work, even though it's documented to do so...
		// $image->setImageOrientation( $orientation['exifCode'] );

		$image->writeImage( $filename );

		// because the above setImageOrientation call doesn't work... nor can I
		// get an external imagemagick binary to do this either... Hacking this
		// for now (only works if you have exiv2 installed, a program to read
		// and manipulate exif).
		if ( $wgExiv2Command ) {
			$command = Shell::command( $wgExiv2Command,
				'-M',
				"set Exif.Image.Orientation {$orientation['exifCode']}",
				$filename
			)->includeStderr();

			$result = $command->execute();
			$retval = $result->getExitCode();
			if ( $retval !== 0 ) {
				print "Error with $command: $retval, {$result->getStdout()}\n";
			}
		}
	}

	/**
	 * Given an image specification, produce rotated version
	 * This is used when simulating a rotated image capture with Exif orientation
	 * @param array &$spec Returned by getImageSpec
	 * @param array $matrix 2x2 transformation matrix
	 * @return array Transformed Spec
	 */
	private static function rotateImageSpec( &$spec, $matrix ) {
		$tSpec = [];
		$dims = self::matrixMultiply2x2( $matrix, $spec['width'], $spec['height'] );
		$correctionX = 0;
		$correctionY = 0;
		if ( $dims['x'] < 0 ) {
			$correctionX = abs( $dims['x'] );
		}
		if ( $dims['y'] < 0 ) {
			$correctionY = abs( $dims['y'] );
		}
		$tSpec['width'] = abs( $dims['x'] );
		$tSpec['height'] = abs( $dims['y'] );
		$tSpec['fill'] = $spec['fill'];
		$tSpec['draws'] = [];
		foreach ( $spec['draws'] as $draw ) {
			$tDraw = [
				'fill' => $draw['fill'],
				'shape' => []
			];
			foreach ( $draw['shape'] as $point ) {
				$tPoint = self::matrixMultiply2x2( $matrix, $point['x'], $point['y'] );
				$tPoint['x'] += $correctionX;
				$tPoint['y'] += $correctionY;
				$tDraw['shape'][] = $tPoint;
			}
			$tSpec['draws'][] = $tDraw;
		}

		return $tSpec;
	}

	/**
	 * Given a matrix and a pair of images, return new position
	 * @param array $matrix 2x2 rotation matrix
	 * @param int $x The x-coordinate number
	 * @param int $y The y-coordinate number
	 * @return array Transformed with properties x, y
	 */
	private static function matrixMultiply2x2( $matrix, $x, $y ) {
		return [
			'x' => $x * $matrix[0][0] + $y * $matrix[0][1],
			'y' => $x * $matrix[1][0] + $y * $matrix[1][1]
		];
	}

	/**
	 * Based on an image specification, write such an image to disk, using the
	 * command line ImageMagick program ('convert').
	 *
	 * Sample command line:
	 *  $ convert -size 100x60 xc:rgb(90,87,45) \
	 *      -draw 'fill rgb(12,34,56)   polygon 41,39 44,57 50,57 41,39' \
	 *   -draw 'fill rgb(99,123,231) circle 59,39 56,57' \
	 *   -draw 'fill rgb(240,12,32)  circle 50,21 50,3'  filename.png
	 *
	 * @param array $spec Spec describing background and shapes to draw
	 * @param string $format File format to write (unused by this method but
	 *   kept so it has the same signature as writeImageWithApi).
	 * @param string $filename Filename to write to
	 *
	 * @return bool
	 */
	public function writeImageWithCommandLine( $spec, $format, $filename ) {
		global $wgImageMagickConvertCommand;

		$args = [
			$wgImageMagickConvertCommand,
			'-size',
			$spec['width'] . 'x' . $spec['height'],
			"xc:{$spec['fill']}",
		];
		foreach ( $spec['draws'] as $draw ) {
			$fill = $draw['fill'];
			$polygon = self::shapePointsToString( $draw['shape'] );
			$drawCommand = "fill $fill  polygon $polygon";
			$args[] = '-draw';
			$args[] = $drawCommand;
		}
		$args[] = $filename;

		$result = Shell::command( $args )->execute();

		return ( $result->getExitCode() === 0 );
	}

	/**
	 * Generate a string of random colors for ImageMagick or SVG, like "rgb(12, 37, 98)"
	 *
	 * @return string
	 */
	public function getRandomColor() {
		$components = [];
		for ( $i = 0; $i <= 2; $i++ ) {
			$components[] = mt_rand( 0, 255 );
		}

		return 'rgb(' . implode( ', ', $components ) . ')';
	}
}
