/**
 * Triptych — repeater field UI for the multilingual structured metabox.
 *
 * Renders a per-row editor (add / remove / drag-reorder) on top of a
 * hidden textarea whose value is a newline-joined string of
 * separator-delimited cells (default separator: `|`). Storage stays the
 * same shape consumers can parse with explode("\n") + explode("|"); the
 * JS just provides ergonomics over the raw string.
 *
 * Each repeater fieldset emits a JSON config + one shadow textarea per
 * language. We:
 *   1. Read the JSON config from `<script class="triptych-rep-config">`.
 *   2. Hydrate per-language row arrays by splitting the shadow textarea.
 *   3. Render rows for the active language.
 *   4. Re-serialize on every mutation back into the shadow textarea so
 *      WordPress's metabox-save submission picks up the edits.
 *   5. Wire language tab clicks to swap visible rows.
 *
 * Drag-reorder uses native HTML5 DnD — no library dependency.
 *
 * No build step. Loaded via `wp_enqueue_script` on post-edit screens
 * that have at least one repeater field registered for the post type.
 */
( function () {
	'use strict';

	function escapeRegex( s ) {
		return String( s ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	function parseLines( raw, separator ) {
		const sepRe = new RegExp( escapeRegex( separator ) );
		const out = [];
		String( raw || '' )
			.split( /\r?\n/ )
			.forEach( ( line ) => {
				if ( ! line.trim() ) return;
				out.push( line.split( sepRe ).map( ( s ) => s.trim() ) );
			} );
		return out;
	}

	function serializeLines( rows, separator, subfields ) {
		const sep = ' ' + separator + ' ';
		return rows
			.map( ( cells ) => {
				const safe = subfields.map( ( sf, i ) => {
					const v = ( cells && cells[ i ] != null ) ? String( cells[ i ] ) : '';
					// Never let the separator char leak into a cell —
					// would corrupt parsing on read. Replace with `/`.
					return v.split( separator ).join( '/' ).trim();
				} );
				while ( safe.length > 1 && safe[ safe.length - 1 ] === '' ) {
					safe.pop();
				}
				if ( safe.every( ( v ) => v === '' ) ) return '';
				return safe.join( sep );
			} )
			.filter( ( s ) => s !== '' )
			.join( '\n' );
	}

	function el( tag, attrs, ...children ) {
		const node = document.createElement( tag );
		if ( attrs ) {
			Object.entries( attrs ).forEach( ( [ k, v ] ) => {
				if ( v == null || v === false ) return;
				if ( k === 'className' ) {
					node.className = v;
				} else if ( k.startsWith( 'on' ) && typeof v === 'function' ) {
					node.addEventListener( k.slice( 2 ).toLowerCase(), v );
				} else if ( k === 'dataset' ) {
					Object.entries( v ).forEach( ( [ dk, dv ] ) => {
						node.dataset[ dk ] = dv;
					} );
				} else {
					node.setAttribute( k, v === true ? '' : v );
				}
			} );
		}
		children.flat().forEach( ( c ) => {
			if ( c == null || c === false ) return;
			node.appendChild(
				typeof c === 'string' ? document.createTextNode( c ) : c
			);
		} );
		return node;
	}

	class Repeater {
		constructor( fieldset ) {
			this.root      = fieldset;
			const cfgNode  = fieldset.querySelector( '.triptych-rep-config' );
			this.config    = JSON.parse( cfgNode.textContent || '{}' );
			this.subfields = this.config.subfields || [];
			this.separator = this.config.separator || '|';
			this.languages = this.config.languages || [];
			this.activeLang = this.config.default || this.languages[ 0 ];
			this.i18n      = this.config.i18n || {};

			// Per-language row arrays. Hydrate from the shadow textarea
			// each language pane already has — that's the canonical
			// source on first render.
			this.rowsByLang = {};
			this.languages.forEach( ( lang ) => {
				const ta = this.shadow( lang );
				const lines = parseLines( ta ? ta.value : '', this.separator );
				this.rowsByLang[ lang ] = lines.map( ( cells ) => {
					const padded = this.subfields.map( ( _, i ) => cells[ i ] || '' );
					return padded;
				} );
			} );

			this.bindTabs();
			this.bindAddButtons();
			this.bindTranslateButtons();
			this.languages.forEach( ( lang ) => this.renderLang( lang ) );
		}

		shadow( lang ) {
			return this.root.querySelector(
				`.triptych-rep-shadow[data-lang="${ lang }"]`
			);
		}
		container( lang ) {
			return this.root.querySelector(
				`.triptych-rep-rows[data-lang="${ lang }"]`
			);
		}

		bindTabs() {
			this.root.querySelectorAll( '.triptych-tab' ).forEach( ( btn ) => {
				btn.addEventListener( 'click', ( e ) => {
					e.preventDefault();
					const lang = btn.dataset.lang;
					if ( ! lang || lang === this.activeLang ) return;
					this.activeLang = lang;
					this.root.querySelectorAll( '.triptych-tab' ).forEach( ( b ) => {
						const on = b.dataset.lang === lang;
						b.classList.toggle( 'is-active', on );
						b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
					} );
					this.root.querySelectorAll( '.triptych-pane' ).forEach( ( p ) => {
						const on = p.dataset.lang === lang;
						p.toggleAttribute( 'hidden', ! on );
						p.classList.toggle( 'hidden', ! on );
					} );
				} );
			} );
		}

		bindAddButtons() {
			this.root.querySelectorAll( '.triptych-rep-add' ).forEach( ( btn ) => {
				btn.addEventListener( 'click', ( e ) => {
					e.preventDefault();
					const lang = btn.dataset.lang;
					if ( ! this.rowsByLang[ lang ] ) this.rowsByLang[ lang ] = [];
					this.rowsByLang[ lang ].push( this.subfields.map( () => '' ) );
					this.renderLang( lang, { focusLast: true } );
				} );
			} );
		}

		bindTranslateButtons() {
			this.root.querySelectorAll( '.triptych-rep-translate' ).forEach( ( btn ) => {
				btn.addEventListener( 'click', async ( e ) => {
					e.preventDefault();
					const from = btn.dataset.from;
					const to   = btn.dataset.to;
					const field = btn.dataset.field;
					if ( ! from || ! to ) return;
					const sourceRows = this.rowsByLang[ from ] || [];
					if ( sourceRows.length === 0 ) {
						this.setStatus( to, this.i18n.noRows || 'No rows yet' );
						return;
					}
					this.setStatus( to, '…' );
					btn.disabled = true;
					try {
						// Translate each cell individually. Pipe-shorthand
						// rows aren't natural prose so we keep cells
						// atomic to avoid the LLM rearranging them.
						const out = [];
						for ( const row of sourceRows ) {
							const translated = [];
							for ( let i = 0; i < row.length; i++ ) {
								const v = row[ i ];
								if ( ! v ) { translated.push( '' ); continue; }
								// Skip translating cells that are pure
								// clock-time / numeric tokens — those
								// round-trip badly through an LLM.
								if ( /^[0-9][0-9:.\s\-→–—]*$/.test( v ) ) {
									translated.push( v );
									continue;
								}
								try {
									const r = await window.wp.apiFetch( {
										path: '/triptych/v1/translate',
										method: 'POST',
										data: { from, to, text: v, field: field + '[' + i + ']' },
									} );
									translated.push( ( r && r.translated ) || v );
								} catch ( err ) {
									translated.push( v );
								}
							}
							out.push( translated );
						}
						this.rowsByLang[ to ] = out;
						this.renderLang( to );
						this.setStatus( to, '✓' );
					} catch ( err ) {
						this.setStatus( to, 'Error: ' + ( err && err.message || err ) );
					} finally {
						btn.disabled = false;
					}
				} );
			} );
		}

		statusEl( lang ) {
			const pane = this.root.querySelector( `.triptych-pane[data-lang="${ lang }"]` );
			return pane ? pane.querySelector( '.triptych-status' ) : null;
		}
		setStatus( lang, msg ) {
			const s = this.statusEl( lang );
			if ( s ) s.textContent = msg;
		}

		writeShadow( lang ) {
			const ta = this.shadow( lang );
			if ( ! ta ) return;
			const rows = this.rowsByLang[ lang ] || [];
			ta.value = serializeLines( rows, this.separator, this.subfields );
		}

		renderLang( lang, opts = {} ) {
			const container = this.container( lang );
			if ( ! container ) return;
			container.innerHTML = '';
			const rows = this.rowsByLang[ lang ] || [];

			if ( rows.length === 0 ) {
				container.appendChild(
					el( 'p', { className: 'triptych-rep-empty' }, this.i18n.noRows || 'No rows yet.' )
				);
				this.writeShadow( lang );
				return;
			}

			rows.forEach( ( cells, rowIndex ) => {
				container.appendChild( this.renderRow( lang, cells, rowIndex ) );
			} );

			if ( opts.focusLast ) {
				const last = container.querySelector(
					'.triptych-rep-row:last-child .triptych-rep-input'
				);
				if ( last ) last.focus();
			}
			this.writeShadow( lang );
		}

		renderRow( lang, cells, rowIndex ) {
			const inputs = this.subfields.map( ( sf, i ) => {
				const value = cells[ i ] != null ? String( cells[ i ] ) : '';
				// `width` hint from the field schema is the `flex:` shorthand
				// for the COLUMN. The flex child of `.triptych-rep-fields` is
				// the wrapping `.triptych-rep-field` <label> (not the inner
				// input), so the style must land on the label or it has no
				// effect on column width distribution.
				const fieldAttrs = { className: 'triptych-rep-field' };
				if ( sf.width ) {
					fieldAttrs.style = 'flex: ' + sf.width + ';';
				}
				return el( 'label', fieldAttrs,
					el( 'span', { className: 'triptych-rep-fieldlabel' }, sf.label || sf.key ),
					el( 'input', {
						type: 'text',
						className: 'triptych-rep-input',
						value: value,
						placeholder: sf.placeholder || '',
						dataset: { col: String( i ) },
						oninput: ( e ) => {
							this.rowsByLang[ lang ][ rowIndex ][ i ] = e.target.value;
							this.writeShadow( lang );
						},
					} )
				);
			} );

			const row = el( 'div', {
				className: 'triptych-rep-row',
				draggable: 'true',
				dataset: { rowIndex: String( rowIndex ) },
			},
				el( 'span', {
					className: 'triptych-rep-handle',
					title: this.i18n.dragRow || 'Drag to reorder',
					'aria-label': this.i18n.dragRow || 'Drag to reorder',
				}, '⋮⋮' ),
				el( 'div', { className: 'triptych-rep-fields' }, inputs ),
				el( 'button', {
					type: 'button',
					className: 'button-link triptych-rep-remove',
					title: this.i18n.removeRow || 'Remove row',
					'aria-label': this.i18n.removeRow || 'Remove row',
					onclick: ( e ) => {
						e.preventDefault();
						this.rowsByLang[ lang ].splice( rowIndex, 1 );
						this.renderLang( lang );
					},
				}, '×' )
			);

			row.addEventListener( 'dragstart', ( e ) => {
				e.dataTransfer.effectAllowed = 'move';
				e.dataTransfer.setData( 'text/plain', String( rowIndex ) );
				row.classList.add( 'is-dragging' );
			} );
			row.addEventListener( 'dragend', () => {
				row.classList.remove( 'is-dragging' );
				this.container( lang )
					.querySelectorAll( '.triptych-rep-row' )
					.forEach( ( r ) => r.classList.remove( 'is-drop-above', 'is-drop-below' ) );
			} );
			row.addEventListener( 'dragover', ( e ) => {
				e.preventDefault();
				e.dataTransfer.dropEffect = 'move';
				const rect = row.getBoundingClientRect();
				const above = e.clientY < rect.top + rect.height / 2;
				row.classList.toggle( 'is-drop-above', above );
				row.classList.toggle( 'is-drop-below', ! above );
			} );
			row.addEventListener( 'dragleave', () => {
				row.classList.remove( 'is-drop-above', 'is-drop-below' );
			} );
			row.addEventListener( 'drop', ( e ) => {
				e.preventDefault();
				const fromIdx = parseInt( e.dataTransfer.getData( 'text/plain' ), 10 );
				if ( isNaN( fromIdx ) || fromIdx === rowIndex ) return;
				const rect = row.getBoundingClientRect();
				const above = e.clientY < rect.top + rect.height / 2;
				const arr = this.rowsByLang[ lang ];
				const moved = arr.splice( fromIdx, 1 )[ 0 ];
				let toIdx = rowIndex + ( above ? 0 : 1 );
				if ( fromIdx < rowIndex ) toIdx -= 1;
				arr.splice( toIdx, 0, moved );
				this.renderLang( lang );
			} );

			return row;
		}
	}

	function init() {
		document
			.querySelectorAll( '.triptych-field-repeater' )
			.forEach( ( fs ) => {
				if ( fs.dataset.triptychInit === '1' ) return;
				fs.dataset.triptychInit = '1';
				try {
					new Repeater( fs );
				} catch ( err ) {
					/* eslint-disable-next-line no-console */
					console.error( 'Triptych repeater init failed', err );
				}
			} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
	// Re-scan after Gutenberg meta-box bridge swaps the DOM (it remounts
	// metaboxes inside an iframe-ish hidden form during save).
	document.addEventListener( 'triptych-rescan', init );
} )();
