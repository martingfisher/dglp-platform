<?php
/**
 * A small PDF writer for the help guides.
 *
 * Text only: Helvetica and Helvetica-Bold, the fourteen fonts every reader
 * carries, so nothing is embedded and no library is needed. Handles a title,
 * headings, paragraphs, bullet lists and numbered steps, wraps lines by
 * measured glyph widths, breaks pages, and numbers them. Pure: no WordPress.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Help;

final class Pdf {

	private const PAGE_W  = 595.28;
	private const PAGE_H  = 841.89;
	private const MARGIN  = 56.0;
	private const BODY    = 10.5;
	private const LEAD    = 15.0;
	private const H1      = 20.0;
	private const H2      = 14.0;
	private const H3      = 11.5;
	private const INDENT  = 16.0;

	/** @var string[] Finished page content streams. */
	private array $pages = [];

	private string $stream = '';

	private float $y = 0.0;

	private string $footer = '';

	/**
	 * @param array{title: string, lede?: string, sections: array<int, array{heading: string, blocks: array<int, array{0: string, 1: mixed}>}>, faqs?: array<int, array{q: string, a: string}>} $doc
	 * @param string $footer Small print at the foot of every page, e.g. the site and the date.
	 */
	public static function render( array $doc, string $footer = '' ): string {
		$pdf         = new self();
		$pdf->footer = $footer;
		$pdf->start_page();

		$pdf->text( (string) $doc['title'], self::H1, true, 26.0 );

		if ( '' !== (string) ( $doc['lede'] ?? '' ) ) {
			$pdf->y -= 4.0;
			$pdf->text( (string) $doc['lede'], self::BODY + 1, false, self::LEAD + 2 );
		}

		foreach ( $doc['sections'] as $section ) {
			$pdf->heading( (string) $section['heading'] );

			foreach ( $section['blocks'] as $block ) {
				$pdf->block( (string) $block[0], $block[1] );
			}
		}

		$faqs = (array) ( $doc['faqs'] ?? [] );

		if ( [] !== $faqs ) {
			$pdf->heading( (string) ( $doc['faq_heading'] ?? 'Questions people ask' ) );

			foreach ( $faqs as $faq ) {
				$pdf->need( self::LEAD * 3 );
				$pdf->y -= 4.0;
				$pdf->text( (string) $faq['q'], self::H3, true, self::LEAD );
				$pdf->text( (string) $faq['a'], self::BODY, false, self::LEAD );
			}
		}

		$pdf->end_page();

		return $pdf->build();
	}

	/* ---- Layout ----------------------------------------------------------- */

	private function heading( string $text ): void {
		$this->need( self::LEAD * 4 );
		$this->y -= 10.0;
		$this->text( $text, self::H2, true, self::LEAD + 4 );
		$this->y -= 2.0;
	}

	/** @param mixed $body */
	private function block( string $kind, $body ): void {
		switch ( $kind ) {
			case 'ul':
				foreach ( (array) $body as $item ) {
					$this->item( "\xE2\x80\xA2", (string) $item );
				}
				$this->y -= 4.0;
				break;
			case 'ol':
				$n = 0;
				foreach ( (array) $body as $item ) {
					$this->item( (string) ++$n . '.', (string) $item );
				}
				$this->y -= 4.0;
				break;
			case 'h3':
				$this->need( self::LEAD * 3 );
				$this->y -= 4.0;
				$this->text( (string) $body, self::H3, true, self::LEAD );
				break;
			default:
				$this->text( (string) $body, self::BODY, false, self::LEAD );
				$this->y -= 4.0;
		}
	}

	private function item( string $marker, string $text ): void {
		$width = self::PAGE_W - 2 * self::MARGIN - self::INDENT;
		$lines = self::wrap( $text, self::BODY, false, $width );
		$this->need( self::LEAD );

		$first = true;
		foreach ( $lines as $line ) {
			$this->need( self::LEAD );
			if ( $first ) {
				$this->draw( $marker, self::MARGIN, $this->y, self::BODY, false );
				$first = false;
			}
			$this->draw( $line, self::MARGIN + self::INDENT, $this->y, self::BODY, false );
			$this->y -= self::LEAD;
		}
	}

	private function text( string $text, float $size, bool $bold, float $lead ): void {
		foreach ( self::wrap( $text, $size, $bold, self::PAGE_W - 2 * self::MARGIN ) as $line ) {
			$this->need( $lead );
			$this->draw( $line, self::MARGIN, $this->y, $size, $bold );
			$this->y -= $lead;
		}
	}

	private function need( float $height ): void {
		if ( $this->y - $height < self::MARGIN + 14.0 ) {
			$this->end_page();
			$this->start_page();
		}
	}

	private function start_page(): void {
		$this->stream = '';
		$this->y      = self::PAGE_H - self::MARGIN;
	}

	private function end_page(): void {
		if ( '' !== $this->footer ) {
			$this->draw( $this->footer, self::MARGIN, self::MARGIN - 14.0, 8.0, false );
		}

		$number = (string) ( count( $this->pages ) + 1 );
		$this->draw( $number, self::PAGE_W - self::MARGIN - self::width( $number, 8.0, false ), self::MARGIN - 14.0, 8.0, false );

		$this->pages[] = $this->stream;
	}

	private function draw( string $text, float $x, float $y, float $size, bool $bold ): void {
		$this->stream .= sprintf(
			"BT /%s %s Tf %s %s Td (%s) Tj ET\n",
			$bold ? 'F2' : 'F1',
			self::num( $size ),
			self::num( $x ),
			self::num( $y ),
			self::escape( self::encode( $text ) )
		);
	}

	/* ---- Measuring and wrapping ------------------------------------------ */

	/**
	 * @return string[]
	 */
	public static function wrap( string $text, float $size, bool $bold, float $width ): array {
		$out   = [];
		$words = preg_split( '/\s+/u', trim( $text ) ) ?: [];
		$line  = '';

		foreach ( $words as $word ) {
			if ( '' === $word ) {
				continue;
			}

			$try = '' === $line ? $word : $line . ' ' . $word;

			if ( self::width( $try, $size, $bold ) <= $width || '' === $line ) {
				$line = $try;
				continue;
			}

			$out[] = $line;
			$line  = $word;
		}

		if ( '' !== $line ) {
			$out[] = $line;
		}

		return [] === $out ? [ '' ] : $out;
	}

	/** Width in points, from Helvetica's metrics; bold runs a little wider. */
	public static function width( string $text, float $size, bool $bold ): float {
		$total = 0;

		foreach ( mb_str_split( $text, 1, 'UTF-8' ) as $char ) {
			$code   = mb_ord( $char, 'UTF-8' );
			$total += self::WIDTHS[ $code ] ?? 556;
		}

		return $total * $size / 1000 * ( $bold ? 1.06 : 1.0 );
	}

	/* ---- Encoding --------------------------------------------------------- */

	/** UTF-8 to WinAnsi, the encoding the core fonts speak; anything else becomes its nearest ASCII. */
	private static function encode( string $text ): string {
		$text = str_replace( [ "\xE2\x80\x93", "\xE2\x80\x94" ], [ '-', '-' ], $text );
		$out  = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $text );

		return false === $out ? preg_replace( '/[^\x20-\x7E]/', '?', $text ) ?? '' : $out;
	}

	private static function escape( string $text ): string {
		return str_replace( [ '\\', '(', ')', "\r", "\n" ], [ '\\\\', '\(', '\)', ' ', ' ' ], $text );
	}

	private static function num( float $n ): string {
		return rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' );
	}

	/* ---- The file --------------------------------------------------------- */

	private function build(): string {
		$objects = [];

		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

		$kids = [];
		$next = 5;

		foreach ( $this->pages as $content ) {
			$page_id    = $next++;
			$content_id = $next++;
			$kids[]     = $page_id . ' 0 R';

			$objects[ $page_id ] = sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
				self::num( self::PAGE_W ),
				self::num( self::PAGE_H ),
				$content_id
			);
			$objects[ $content_id ] = sprintf( "<< /Length %d >>\nstream\n%s\nendstream", strlen( $content ), $content );
		}

		$objects[2] = sprintf( '<< /Type /Pages /Kids [%s] /Count %d >>', implode( ' ', $kids ), count( $kids ) );

		ksort( $objects );

		$out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];

		foreach ( $objects as $id => $body ) {
			$offsets[ $id ] = strlen( $out );
			$out           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}

		$count = count( $objects ) + 1;
		$xref  = strlen( $out );
		$out  .= "xref\n0 " . $count . "\n0000000000 65535 f \n";

		for ( $i = 1; $i < $count; $i++ ) {
			$out .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}

		$out .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

		return $out;
	}

	/** Helvetica glyph widths for WinAnsi, per 1000 em, from the Adobe AFM. */
	private const WIDTHS = [
		32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191, 40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
		48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556, 56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556,
		64 => 1015, 65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778,
		80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556,
		96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556, 104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556,
		112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584,
		163 => 556, 8226 => 350, 8217 => 222, 8216 => 222, 8220 => 333, 8221 => 333, 233 => 556, 232 => 556, 224 => 556, 231 => 500,
	];
}
