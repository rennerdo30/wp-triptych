/**
 * Triptych — in-canvas language UI for the Block Editor.
 *
 * The canvas IS the editor. Switching language replaces the title +
 * blocks with that language's stored content. Saving in a non-default
 * language writes to `_triptych_<field>_<lang>` postmeta and never
 * overwrites the default-language source columns.
 *
 * Layers:
 *   1. Header pill bar — CN | JP | EN, status dot per language, plus
 *      "Save translation" + "Translate post" action buttons. Mounted
 *      via React portal into the editor's header center slot.
 *   2. Per-block toolbar button — paragraph / heading / list / quote /
 *      table / code / button get a Translate icon. Click → DeepSeek
 *      translates just that block's text content.
 *
 * No JSX — pure wp.element.createElement. No build step required.
 */
( function ( wp ) {
	'use strict';

	const { registerPlugin }    = wp.plugins;
	const {
		Button, ButtonGroup, Notice,
		ToolbarButton, ToolbarGroup,
	} = wp.components;
	const {
		useState, useEffect, useCallback, useRef,
		createElement: el, Fragment, createPortal,
	} = wp.element;
	const { useSelect, useDispatch, select, dispatch } = wp.data;
	const { __, sprintf } = wp.i18n;
	const { addFilter }   = wp.hooks;
	const { createHigherOrderComponent } = wp.compose;
	const apiFetch        = wp.apiFetch;
	const { BlockControls } = wp.blockEditor;
	const { serialize, parse } = wp.blocks;

	const cfg = window.TriptychEditor || { languages: { en: 'English' }, default: 'en', adminLang: 'en', i18n: {} };
	const langKeys = Object.keys( cfg.languages );
	const t = ( k, fb ) => cfg.i18n[ k ] || fb || k;
	// Resolve the user's preferred admin lang. Honour the value the
	// server localized (driven by the admin-bar switcher's user meta);
	// fall back to default if the slug is missing or unregistered.
	const initialLang = ( cfg.adminLang && cfg.languages[ cfg.adminLang ] )
		? cfg.adminLang
		: cfg.default;

	const TRIPTYCH_SAVE_LOCK = 'triptych-non-default-lang';

	// ── Module-scope state shared between the bar, the BlockControls
	// extension, and the save subscriber.
	const triptychState = {
		activeLang: initialLang,
		postId:     0,
		// Per-language stored snapshot of source-block hashes (captured
		// at translate / save time). Used to mark blocks "stale" when
		// the live source-language hash diverges.
		storedHashes: {},   // { lang: [hash0, hash1, …] }
		// Cached source-language tree (the default-lang content) so
		// non-default block wrappers can pull the matching source block.
		sourceContent: '',
		// Indices of stale blocks for the active non-default language —
		// recomputed by HeaderBar whenever language switches or content
		// edits land. The block-toolbar HOC reads from here.
		staleSet:   new Set(),
		listeners:  new Set(),
	};
	function setActive( lang ) {
		triptychState.activeLang = lang;
		triptychState.listeners.forEach( ( fn ) => fn() );
	}
	function setStaleSet( indices ) {
		triptychState.staleSet = new Set( indices );
		triptychState.listeners.forEach( ( fn ) => fn() );
	}
	function setStored( lang, hashes ) {
		triptychState.storedHashes[ lang ] = Array.isArray( hashes ) ? hashes.slice() : [];
		triptychState.listeners.forEach( ( fn ) => fn() );
	}
	function onChange( fn ) {
		triptychState.listeners.add( fn );
		return () => triptychState.listeners.delete( fn );
	}
	function useActiveLang() {
		const [ , force ] = useState( 0 );
		useEffect( () => onChange( () => force( ( n ) => n + 1 ) ), [] );
		return triptychState.activeLang;
	}
	function useStaleIndex( index ) {
		const [ , force ] = useState( 0 );
		useEffect( () => onChange( () => force( ( n ) => n + 1 ) ), [] );
		return triptychState.staleSet.has( index );
	}

	// ── DJB2 hash, used for block-content drift detection. Cheap, no
	// crypto deps; collisions don't matter — false positives just mean
	// a re-translate prompt the editor can dismiss.
	function djb2( s ) {
		s = String( s || '' );
		let h = 5381;
		for ( let i = 0; i < s.length; i++ ) h = ( ( h << 5 ) + h + s.charCodeAt( i ) ) | 0;
		return ( h >>> 0 ).toString( 36 );
	}
	function blockHash( block ) {
		const def = TRANSLATABLE_BLOCKS[ block.name ];
		if ( ! def || def.attrs.length === 0 ) return '';
		const parts = def.attrs.map( ( k ) => block.attributes && block.attributes[ k ] || '' );
		return djb2( block.name + '␟' + parts.join( '␟' ) );
	}
	function hashSourceBlocks( content ) {
		try {
			const blocks = parse( content || '' );
			return blocks.map( blockHash );
		} catch ( e ) {
			return [];
		}
	}

	// ── Block translatability table.
	const TRANSLATABLE_BLOCKS = {
		'core/paragraph':    { attrs: [ 'content' ] },
		'core/heading':      { attrs: [ 'content' ] },
		'core/list-item':    { attrs: [ 'content' ] },
		'core/list':         { attrs: [] },
		'core/quote':        { attrs: [ 'value', 'citation' ] },
		'core/pullquote':    { attrs: [ 'value', 'citation' ] },
		'core/code':         { attrs: [ 'content' ] },
		'core/preformatted': { attrs: [ 'content' ] },
		'core/verse':        { attrs: [ 'content' ] },
		'core/table':        { attrs: [ 'caption' ] },
		'core/button':       { attrs: [ 'text' ] },
		'core/buttons':      { attrs: [] },
		'core/image':        { attrs: [ 'alt', 'caption' ] },
	};

	// ── REST helpers
	async function fetchState( postId ) {
		return apiFetch( { path: `/triptych/v1/post/${ postId }` } );
	}
	async function saveValue( postId, field, lang, value, sourceHashes ) {
		const data = { post_id: postId, field, lang, value };
		if ( Array.isArray( sourceHashes ) ) {
			data.source_hashes = sourceHashes;
		}
		return apiFetch( { path: '/triptych/v1/save', method: 'POST', data } );
	}
	async function callTranslate( from, to, text, field ) {
		const r = await apiFetch( {
			path: '/triptych/v1/translate',
			method: 'POST',
			data: { from, to, text, field: field || '' },
		} );
		if ( ! r || typeof r.translated !== 'string' ) {
			throw new Error( 'Bad translate-endpoint response' );
		}
		return r.translated;
	}

	// ── Editor state helpers
	function getEditorTitle() {
		return select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
	}
	function getEditorContent() {
		// Live block tree → canonical block markup. Reading the
		// 'content' attribute returns the on-load source only.
		const blocks = select( 'core/block-editor' ).getBlocks();
		return serialize( blocks );
	}
	function setEditorTitle( value ) {
		dispatch( 'core/editor' ).editPost( { title: value } );
	}
	function setEditorContent( value ) {
		const blocks = parse( value || '' );
		dispatch( 'core/block-editor' ).resetBlocks( blocks );
		// Mark the editor's content attribute so subsequent serializations
		// see the new state without flagging the post as dirty.
		dispatch( 'core/editor' ).editPost( { content: value } );
	}

	// ── Header bar: language pills + actions ───────────────────────
	function HeaderBar() {
		const activeLang = useActiveLang();
		const { postId, sourceTitle, sourceContent } = useSelect( ( s ) => {
			const ed = s( 'core/editor' );
			return {
				postId:        ed.getCurrentPostId(),
				sourceTitle:   ed.getEditedPostAttribute( 'title' ) || '',
				sourceContent: ed.getEditedPostAttribute( 'content' ) || '',
			};
		}, [] );

		const [ state, setState ]       = useState( null );
		const [ busyLang, setBusyLang ] = useState( null );
		const [ saveBusy, setSaveBusy ] = useState( false );
		const [ error, setError ]       = useState( null );
		const cacheRef = useRef( {} );  // { lang: { title, content } } unsaved swaps
		const initialSwitchRef = useRef( false );  // one-shot guard for adminLang preselect
		const noticesDispatch = useDispatch( 'core/notices' );

		const refresh = useCallback( async () => {
			if ( ! postId ) return;
			try {
				const data = await fetchState( postId );
				setState( data );
				triptychState.postId = postId;
				// Cache stored block-hash snapshots per language so the
				// stale-set comparator (below) and the block-toolbar HOC
				// can read them without their own fetch round-trips.
				const cf = data.fields && data.fields.post_content || { values: {} };
				Object.keys( cf.values || {} ).forEach( ( lang ) => {
					setStored( lang, ( cf.values[ lang ] || {} ).source_hashes || [] );
				} );
			} catch ( err ) {
				setError( err && err.message ? err.message : String( err ) );
			}
		}, [ postId ] );

		useEffect( () => { refresh(); }, [ refresh ] );

		// One-shot: once the post state has loaded, if the user has a
		// preferred admin language that isn't the source, swap the canvas
		// to that language's stored content. Honours the toolbar choice
		// without requiring the editor to click a pill manually.
		useEffect( () => {
			if ( initialSwitchRef.current ) return;
			if ( ! state ) return;
			if ( initialLang === cfg.default ) {
				initialSwitchRef.current = true;
				return;
			}
			const tField = state.fields.post_title   || { values: {} };
			const cField = state.fields.post_content || { values: {} };
			const nextTitle   = ( tField.values[ initialLang ] || {} ).value || '';
			const nextContent = ( cField.values[ initialLang ] || {} ).value || '';
			// Stash the current source-lang canvas before swapping so a
			// later return to source doesn't lose the in-memory edits.
			cacheRef.current[ cfg.default ] = {
				title:   getEditorTitle(),
				content: getEditorContent(),
			};
			setEditorTitle( nextTitle );
			setEditorContent( nextContent );
			setActive( initialLang );
			initialSwitchRef.current = true;
		}, [ state ] );

		// Recompute the stale-block set whenever the active language
		// changes or the live source content changes. We hash the
		// CURRENT default-language source (held in cacheRef when a
		// non-default lang is active) and compare element-wise against
		// the stored hashes captured at translate time.
		useEffect( () => {
			if ( activeLang === cfg.default ) {
				setStaleSet( [] );
				triptychState.sourceContent = sourceContent;
				return;
			}
			const cachedDefault = cacheRef.current[ cfg.default ];
			const liveDefault   = cachedDefault ? cachedDefault.content : sourceContent;
			triptychState.sourceContent = liveDefault;

			const stored = triptychState.storedHashes[ activeLang ] || [];
			const live   = hashSourceBlocks( liveDefault );

			const stale = [];
			const len = Math.max( stored.length, live.length );
			for ( let i = 0; i < len; i++ ) {
				if ( stored[ i ] === undefined ) {
					// Source has more blocks than translated → those new
					// blocks are stale (untranslated) at index i.
					stale.push( i );
				} else if ( stored[ i ] !== live[ i ] ) {
					stale.push( i );
				}
			}
			setStaleSet( stale );
		}, [ activeLang, sourceContent, state ] );

		// Saving in a non-default language must NOT write to post_content.
		// We block Gutenberg's native save while non-default is active —
		// the dedicated "Save translation" button persists to postmeta.
		useEffect( () => {
			if ( activeLang === cfg.default ) {
				dispatch( 'core/editor' ).unlockPostSaving( TRIPTYCH_SAVE_LOCK );
			} else {
				dispatch( 'core/editor' ).lockPostSaving( TRIPTYCH_SAVE_LOCK );
			}
		}, [ activeLang ] );

		const switchLang = useCallback( ( target ) => {
			if ( target === activeLang ) return;

			// Stash current canvas state under the old lang in case the
			// user switches back without saving.
			cacheRef.current[ activeLang ] = {
				title:   getEditorTitle(),
				content: getEditorContent(),
			};

			// Pull next lang content from cache → state snapshot → empty.
			let nextTitle = '';
			let nextContent = '';
			const cached = cacheRef.current[ target ];
			if ( cached ) {
				nextTitle   = cached.title;
				nextContent = cached.content;
			} else if ( state ) {
				const tField = state.fields.post_title   || { values: {} };
				const cField = state.fields.post_content || { values: {} };
				nextTitle   = ( tField.values[ target ] || {} ).value || '';
				nextContent = ( cField.values[ target ] || {} ).value || '';
				if ( target === cfg.default ) {
					if ( ! nextTitle )   nextTitle   = sourceTitle;
					if ( ! nextContent ) nextContent = sourceContent;
				}
			}

			setEditorTitle( nextTitle );
			setEditorContent( nextContent );
			setActive( target );
		}, [ activeLang, state, sourceTitle, sourceContent ] );

		const saveTranslation = useCallback( async () => {
			if ( ! postId ) {
				noticesDispatch.createNotice( 'warning', t( 'savePostFirst', 'Save the post once before translating.' ) );
				return;
			}
			setSaveBusy( true );
			setError( null );
			try {
				const title   = getEditorTitle();
				const content = getEditorContent();
				if ( activeLang === cfg.default ) {
					dispatch( 'core/editor' ).unlockPostSaving( TRIPTYCH_SAVE_LOCK );
					await dispatch( 'core/editor' ).savePost();
					dispatch( 'core/editor' ).lockPostSaving( TRIPTYCH_SAVE_LOCK );
				} else {
					// Snapshot the source-language block hashes at save
					// time. The user has just declared "this translation
					// matches the current source" so we capture the live
					// default-lang content as the canonical reference.
					const liveSrc = ( cacheRef.current[ cfg.default ] || {} ).content || sourceContent;
					const hashes  = hashSourceBlocks( liveSrc );
					await Promise.all( [
						saveValue( postId, 'post_title',   activeLang, title,   hashes ),
						saveValue( postId, 'post_content', activeLang, content, hashes ),
					] );
					setStored( activeLang, hashes );
					setStaleSet( [] );
					noticesDispatch.createNotice(
						'success',
						t( 'savedTranslation', 'Translation saved' ),
						{ type: 'snackbar', isDismissible: true }
					);
				}
				await refresh();
			} catch ( err ) {
				const msg = err && err.message ? err.message : String( err );
				setError( msg );
			} finally {
				setSaveBusy( false );
			}
		}, [ postId, activeLang, refresh, noticesDispatch ] );

		const translateWholePost = useCallback( async ( target ) => {
			if ( ! postId ) {
				noticesDispatch.createNotice( 'warning', t( 'savePostFirst', 'Save the post once before translating.' ) );
				return;
			}
			setBusyLang( target );
			setError( null );
			try {
				let srcTitle, srcContent;
				if ( activeLang === cfg.default ) {
					srcTitle   = getEditorTitle();
					srcContent = getEditorContent();
				} else if ( state ) {
					srcTitle   = ( state.fields.post_title   || { values: {} } ).values[ cfg.default ]?.value || sourceTitle;
					srcContent = ( state.fields.post_content || { values: {} } ).values[ cfg.default ]?.value || sourceContent;
				} else {
					srcTitle   = sourceTitle;
					srcContent = sourceContent;
				}
				if ( ! ( srcTitle || '' ).trim() && ! ( srcContent || '' ).trim() ) {
					throw new Error( 'Source content is empty.' );
				}

				const [ tTitle, tContent ] = await Promise.all( [
					( srcTitle   || '' ).trim() ? callTranslate( cfg.default, target, srcTitle, 'post_title' )     : Promise.resolve( '' ),
					( srcContent || '' ).trim() ? callTranslate( cfg.default, target, srcContent, 'post_content' ) : Promise.resolve( '' ),
				] );

				const hashes = hashSourceBlocks( srcContent );
				await Promise.all( [
					tTitle   ? saveValue( postId, 'post_title',   target, tTitle,   hashes ) : Promise.resolve(),
					tContent ? saveValue( postId, 'post_content', target, tContent, hashes ) : Promise.resolve(),
				] );
				setStored( target, hashes );

				cacheRef.current[ target ] = { title: tTitle, content: tContent };
				if ( activeLang === target ) {
					setEditorTitle( tTitle );
					setEditorContent( tContent );
					setStaleSet( [] );
				}
				await refresh();
				noticesDispatch.createNotice(
					'success',
					sprintf( t( 'translatedAgo', 'Translated %s' ), target.toUpperCase() ),
					{ type: 'snackbar' }
				);
			} catch ( err ) {
				const msg = err && err.message ? err.message : String( err );
				setError( sprintf( t( 'translateError', 'Translation failed: %s' ), msg ) );
			} finally {
				setBusyLang( null );
			}
		}, [ postId, state, activeLang, sourceTitle, sourceContent, refresh, noticesDispatch ] );

		const buildPill = ( lang ) => {
			const label    = cfg.languages[ lang ] || lang;
			const isActive = lang === activeLang;
			const fields   = state ? state.fields : {};
			const tStatus  = ( fields.post_title   || { values: {} } ).values[ lang ] || {};
			const cStatus  = ( fields.post_content || { values: {} } ).values[ lang ] || {};
			let dot = 'is-empty';
			if ( lang === cfg.default ) dot = 'is-source';
			else if ( tStatus.has_envelope || cStatus.has_envelope ) dot = 'is-translated';
			else if ( tStatus.has_value || cStatus.has_value ) dot = 'is-legacy';

			return el( 'button', {
				key:   lang,
				type:  'button',
				className: 'triptych-pill' + ( isActive ? ' is-active' : '' ),
				onClick: () => switchLang( lang ),
				'aria-pressed': isActive,
				title: label,
			},
				el( 'span', { className: 'triptych-pill-slug' }, lang.toUpperCase() ),
				el( 'span', { className: 'triptych-pill-dot ' + dot, 'aria-hidden': true } )
			);
		};

		const isNonDefault = activeLang !== cfg.default;

		return el( 'div', { className: 'triptych-bar' },
			el( 'div', { className: 'triptych-bar-pills' },
				el( 'span', { className: 'triptych-bar-label' }, t( 'switchLabel', 'Language' ) ),
				el( ButtonGroup, { className: 'triptych-pillgroup' }, langKeys.map( buildPill ) )
			),
			el( 'div', { className: 'triptych-bar-actions' },
				isNonDefault && el( Button, {
					variant:  'primary',
					isBusy:   saveBusy,
					disabled: saveBusy,
					onClick:  saveTranslation,
				}, t( 'saveTranslation', 'Save translation' ) ),
				isNonDefault && el( Button, {
					variant:  'secondary',
					isBusy:   busyLang === activeLang,
					disabled: busyLang === activeLang,
					onClick:  () => translateWholePost( activeLang ),
				}, t( 'translatePost', 'Translate post' ) )
			),
			error && el( Notice, {
				className: 'triptych-bar-error',
				status:    'error',
				isDismissible: true,
				onRemove:  () => setError( null ),
			}, error )
		);
	}

	// ── Mount the header bar via portal once the editor frame exists.
	function HeaderBarMount() {
		const [ anchor, setAnchor ] = useState( null );
		useEffect( () => {
			let cancelled = false;
			let rafId;
			const find = () => {
				if ( cancelled ) return;
				const target =
					document.querySelector( '.editor-header__center' ) ||
					document.querySelector( '.edit-post-header__center' ) ||
					document.querySelector( '.edit-post-header__settings' ) ||
					document.querySelector( '.edit-post-header' );
				if ( target ) {
					let host = target.querySelector( ':scope > .triptych-bar-host' );
					if ( ! host ) {
						host = document.createElement( 'div' );
						host.className = 'triptych-bar-host';
						target.appendChild( host );
					}
					setAnchor( host );
					return;
				}
				rafId = requestAnimationFrame( find );
			};
			find();
			return () => {
				cancelled = true;
				if ( rafId ) cancelAnimationFrame( rafId );
			};
		}, [] );
		if ( ! anchor ) return null;
		return createPortal( el( HeaderBar ), anchor );
	}

	// ── Per-block toolbar button + stale badge ────────────────────
	const withTriptychToolbar = createHigherOrderComponent( ( BlockEdit ) => {
		return function TriptychBlockEdit( props ) {
			const { name, attributes, setAttributes, isSelected, clientId } = props;
			const def = TRANSLATABLE_BLOCKS[ name ];
			const activeLang = useActiveLang();
			const [ busy, setBusy ] = useState( false );

			// Compute this block's index inside the top-level block list
			// so we can compare against the stored source-hash array.
			const blockIndex = useSelect( ( s ) => {
				if ( activeLang === cfg.default ) return -1;
				const order = s( 'core/block-editor' ).getBlockOrder();
				const idx = order.indexOf( clientId );
				return idx;
			}, [ clientId, activeLang ] );

			const isStale = useStaleIndex( blockIndex );

			if ( activeLang === cfg.default ) {
				return el( BlockEdit, props );
			}
			if ( ! def ) {
				// Non-translatable block (image, embed, spacer, …) — still
				// render a stale indicator if the index drifted, but skip
				// the translate toolbar.
				if ( isStale && isSelected ) {
					return el( Fragment, null,
						el( BlockEdit, props ),
						el( BlockControls, null,
							el( ToolbarGroup, null,
								el( ToolbarButton, {
									icon:  'warning',
									label: t( 'sourceChanged', 'Source has changed since this translation was made.' ),
									disabled: true,
								} )
							)
						)
					);
				}
				return el( BlockEdit, props );
			}
			if ( ! isSelected && ! isStale ) {
				return el( BlockEdit, props );
			}

			const onTranslate = async () => {
				// Pull the SOURCE-language version of this block so we
				// translate from the canonical source, not from whatever
				// is currently in the non-default canvas.
				let sourceText = '';
				if ( triptychState.sourceContent && blockIndex >= 0 ) {
					try {
						const srcBlocks = parse( triptychState.sourceContent );
						const srcBlock  = srcBlocks[ blockIndex ];
						if ( srcBlock && srcBlock.name === name ) {
							sourceText = def.attrs
								.map( ( k ) => srcBlock.attributes && srcBlock.attributes[ k ] || '' )
								.filter( ( v ) => typeof v === 'string' && v.trim() !== '' )
								.join( '\n' );
						}
					} catch ( e ) {
						// Fall through to attribute-based source.
					}
				}
				if ( ! sourceText ) {
					sourceText = def.attrs
						.map( ( k ) => attributes[ k ] || '' )
						.filter( ( v ) => typeof v === 'string' && v.trim() !== '' )
						.join( '\n' );
				}
				if ( ! sourceText ) {
					dispatch( 'core/notices' ).createNotice(
						'info',
						t( 'untranslatable', 'This block has no translatable text.' ),
						{ type: 'snackbar' }
					);
					return;
				}
				setBusy( true );
				try {
					if ( def.attrs.length === 1 ) {
						const k = def.attrs[ 0 ];
						let srcVal = attributes[ k ] || '';
						if ( triptychState.sourceContent && blockIndex >= 0 ) {
							try {
								const srcBlocks = parse( triptychState.sourceContent );
								if ( srcBlocks[ blockIndex ] && srcBlocks[ blockIndex ].attributes ) {
									srcVal = srcBlocks[ blockIndex ].attributes[ k ] || srcVal;
								}
							} catch ( e ) {}
						}
						const out = await callTranslate( cfg.default, activeLang, srcVal, 'block_' + name + '_' + k );
						setAttributes( { [ k ]: out } );
					} else {
						const updates = {};
						let srcAttrs = attributes;
						if ( triptychState.sourceContent && blockIndex >= 0 ) {
							try {
								const srcBlocks = parse( triptychState.sourceContent );
								if ( srcBlocks[ blockIndex ] && srcBlocks[ blockIndex ].attributes ) {
									srcAttrs = srcBlocks[ blockIndex ].attributes;
								}
							} catch ( e ) {}
						}
						for ( const k of def.attrs ) {
							const v = srcAttrs[ k ];
							if ( typeof v === 'string' && v.trim() !== '' ) {
								updates[ k ] = await callTranslate( cfg.default, activeLang, v, 'block_' + name + '_' + k );
							}
						}
						setAttributes( updates );
					}

					// Refresh the stale flag for this index — current
					// source hash now matches the freshly translated
					// block, so drop it from the stale set.
					if ( blockIndex >= 0 && triptychState.sourceContent ) {
						const srcBlocks = parse( triptychState.sourceContent );
						const live = srcBlocks.map( blockHash );
						const stored = ( triptychState.storedHashes[ activeLang ] || [] ).slice();
						stored[ blockIndex ] = live[ blockIndex ] || '';
						setStored( activeLang, stored );
						const stale = [];
						for ( let i = 0; i < Math.max( stored.length, live.length ); i++ ) {
							if ( stored[ i ] === undefined || stored[ i ] !== live[ i ] ) stale.push( i );
						}
						setStaleSet( stale );
					}
				} catch ( err ) {
					const msg = err && err.message ? err.message : String( err );
					dispatch( 'core/notices' ).createNotice(
						'error',
						sprintf( t( 'translateError', 'Translation failed: %s' ), msg ),
						{ type: 'snackbar' }
					);
				} finally {
					setBusy( false );
				}
			};

			return el( Fragment, null,
				isStale && el( 'div', { className: 'triptych-stale-badge' },
					el( 'span', { className: 'triptych-stale-dot', 'aria-hidden': true } ),
					el( 'span', { className: 'triptych-stale-text' },
						t( 'sourceChanged', 'Source has changed since this translation was made.' )
					),
					el( Button, {
						className: 'triptych-stale-btn',
						isSmall: true,
						variant: 'primary',
						isBusy:  busy,
						onClick: onTranslate,
					}, t( 'reTranslate', 'Re-translate' ) )
				),
				el( BlockEdit, props ),
				isSelected && el( BlockControls, null,
					el( ToolbarGroup, null,
						el( ToolbarButton, {
							icon:     busy ? 'update' : 'translation',
							label:    t( 'translateBlock', 'Translate block' ),
							onClick:  onTranslate,
							disabled: busy,
						} )
					)
				)
			);
		};
	}, 'withTriptychToolbar' );

	addFilter(
		'editor.BlockEdit',
		'triptych/with-translate-toolbar',
		withTriptychToolbar
	);

	registerPlugin( 'triptych-language-bar', { render: HeaderBarMount, icon: 'translation' } );

} )( window.wp );
