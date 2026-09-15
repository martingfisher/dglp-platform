/**
 * Walk the member area and fail on any text that cannot be read.
 *
 * The palette is already asserted in tests/test-brand.php, but that checks the
 * colours we *intend* to pair. It cannot catch what actually renders: a button
 * lost its white text to a more specific `.dgl-dash a` rule and spent weeks at
 * about 2.1:1, purple on navy, because every test we had was looking at the
 * palette rather than at the page.
 *
 * Usage:
 *   node bin/check-contrast.mjs <base-url> <user> <password>
 *
 * Exits non-zero if anything is below WCAG AA.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';

const [ base, user, pass ] = process.argv.slice( 2 );

if ( ! base || ! user || ! pass ) {
	console.error( 'usage: node bin/check-contrast.mjs <base-url> <user> <password>' );
	process.exit( 2 );
}

/* Extra routes can be passed after the password, for ones that need an ID. */
const ROUTES = [
	'/dashboard/',
	'/dashboard/events',
	'/dashboard/archive',
	'/dashboard/notifications',
	'/dashboard/profile/organisation',
	'/dashboard/profile/members',
	'/dashboard/profile/signin',
	'/dashboard/new/events',
	'/dashboard/review',
	...process.argv.slice( 5 ),
];

/** Relative luminance, WCAG 2.1. */
function luminance( colour ) {
	const parts = colour.match( /[\d.]+/g );

	if ( ! parts || parts.length < 3 ) {
		return null;
	}

	const [ r, g, b ] = parts.slice( 0, 3 ).map( Number ).map( ( v ) => {
		const c = v / 255;
		return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
	} );

	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function ratio( fg, bg ) {
	const a = luminance( fg );
	const b = luminance( bg );

	if ( null === a || null === b ) {
		return null;
	}

	return ( Math.max( a, b ) + 0.05 ) / ( Math.min( a, b ) + 0.05 );
}

const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 1200, height: 900 } } );

await page.goto( base + '/wp-login.php' );
await page.fill( '#user_login', user );
await page.fill( '#user_pass', pass );
await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

const failures = [];
let checked = 0;

for ( const route of ROUTES ) {
	await page.goto( base + route );

	/*
	 * Walking up for the first non-transparent ancestor background, because an
	 * element's own background is usually transparent and comparing text
	 * against "rgba(0, 0, 0, 0)" would pass everything.
	 */
	const samples = await page.evaluate( () => {
		const parse = ( c ) => {
			const n = ( c || '' ).match( /[\d.]+/g );
			if ( ! n || n.length < 3 ) {
				return null;
			}
			return {
				r: +n[ 0 ],
				g: +n[ 1 ],
				b: +n[ 2 ],
				a: undefined === n[ 3 ] ? 1 : +n[ 3 ],
			};
		};

		/*
		 * Composite translucent layers rather than treating the first one as
		 * the answer. An active nav item is white text on rgba(255,255,255,.12)
		 * over navy: read literally that is white on white and reports 1.00,
		 * which is a checker bug, not a design one. Blending it gives the
		 * colour a person actually sees.
		 */
		const backdrop = ( el ) => {
			const layers = [];
			let node = el;

			while ( node && node !== document.documentElement ) {
				const bg = parse( getComputedStyle( node ).backgroundColor );
				if ( bg && bg.a > 0 ) {
					layers.push( bg );
					if ( bg.a >= 1 ) {
						break;
					}
				}
				node = node.parentElement;
			}

			const base = parse( getComputedStyle( document.body ).backgroundColor ) || { r: 255, g: 255, b: 255, a: 1 };
			let out = layers.length && 1 === layers[ layers.length - 1 ].a ? layers.pop() : base;

			// Back to front, so the nearest layer lands on top.
			for ( let i = layers.length - 1; i >= 0; i-- ) {
				const top = layers[ i ];
				out = {
					r: top.r * top.a + out.r * ( 1 - top.a ),
					g: top.g * top.a + out.g * ( 1 - top.a ),
					b: top.b * top.a + out.b * ( 1 - top.a ),
					a: 1,
				};
			}

			return `rgb(${ Math.round( out.r ) }, ${ Math.round( out.g ) }, ${ Math.round( out.b ) })`;
		};

		return [ ...document.querySelectorAll( '.dgl-dash, .dgl-topbar' ) ]
			.flatMap( ( root ) => [ ...root.querySelectorAll( '*' ) ] )
			.filter( ( el ) => {
				// Only elements that actually paint their own words.
				const text = [ ...el.childNodes ]
					.filter( ( n ) => 3 === n.nodeType )
					.map( ( n ) => n.textContent.trim() )
					.join( '' );

				if ( '' === text ) {
					return false;
				}

				const box = el.getBoundingClientRect();
				return box.width > 0 && box.height > 0;
			} )
			.map( ( el ) => {
				const cs = getComputedStyle( el );
				return {
					text: el.textContent.trim().slice( 0, 40 ),
					colour: cs.color,
					background: backdrop( el ),
					size: parseFloat( cs.fontSize ),
					weight: parseInt( cs.fontWeight, 10 ) || 400,
				};
			} );
	} );

	for ( const s of samples ) {
		const value = ratio( s.colour, s.background );

		if ( null === value ) {
			continue;
		}

		++checked;

		// WCAG's large-text allowance: 18.66px bold, or 24px at any weight.
		const large = s.size >= 24 || ( s.size >= 18.66 && s.weight >= 700 );
		const floor = large ? 3 : 4.5;

		if ( value < floor ) {
			failures.push( {
				route,
				text: s.text,
				ratio: value.toFixed( 2 ),
				needs: floor,
				colour: s.colour,
				background: s.background,
			} );
		}
	}
}

await browser.close();

console.log( `${ checked } text/background pairs checked across ${ ROUTES.length } routes` );

if ( 0 === failures.length ) {
	console.log( 'All clear. Nothing below WCAG AA.' );
	process.exit( 0 );
}

console.log( `\n${ failures.length } below AA:\n` );

for ( const f of failures ) {
	console.log( `  ${ f.ratio } (needs ${ f.needs })  ${ f.route }  "${ f.text }"` );
	console.log( `        ${ f.colour } on ${ f.background }` );
}

process.exit( 1 );
