/**
 * Triptych — Block Editor sidebar plugin.
 *
 * One-click AI translation per non-default language. The Block Editor is
 * the source-of-truth for the default language (post_title + post_content
 * read live from `core/editor`). For non-default languages — and for any
 * custom multilingual field declared via Triptych\Fields::register() —
 * this sidebar renders a small panel per field showing:
 *
 *   • Last-translated timestamp (or "Not translated yet")
 *   • A "Translate from <default>" button that POSTs the source to the
 *     /triptych/v1/translate endpoint and saves the result via
 *     /triptych/v1/save
 *   • An expandable text area so the editor can hand-tweak the auto
 *     translation
 *
 * No JSX (so no build step). Pure wp.element.createElement.
 */
( function ( wp ) {
	'use strict';

	const { registerPlugin }                  = wp.plugins;
	const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
	const { PanelBody, Button, Spinner, Notice, TextControl, TextareaControl } = wp.components;
	const { useState, useEffect, useCallback, createElement: el, Fragment } = wp.element;
	const { useSelect, useDispatch }          = wp.data;
	const { __, sprintf }                     = wp.i18n;
	const apiFetch                            = wp.apiFetch;

	const cfg = window.TriptychSidebar || { languages: {}, default: 'en', i18n: {} };
	const t   = ( key, fallback ) => ( cfg.i18n[ key ] || fallback || key );

	function fmtRelative( ts ) {
		if ( ! ts ) return '';
		const diff = Math.max( 0, Math.floor( Date.now() / 1000 ) - Number( ts ) );
		if ( diff < 60 )       return 'just now';
		if ( diff < 3600 )     return Math.floor( diff /   60 ) + 'm ago';
		if ( diff < 86400 )    return Math.floor( diff / 3600 ) + 'h ago';
		if ( diff < 86400*30 ) return Math.floor( diff / 86400 ) + 'd ago';
		const d = new Date( ts * 1000 );
		return d.toISOString().slice( 0, 10 );
	}

	function FieldPanel( props ) {
		const { postId, fieldName, def, defaultLang, languages, sourceLive, refreshState } = props;
		const [ busy, setBusy ]   = useState( null );    // null | <lang slug>
		const [ error, setError ] = useState( null );
		const [ openLang, setOpenLang ] = useState( null );

		const sourceText = sourceLive !== undefined && sourceLive !== null
			? sourceLive
			: ( def.values[ defaultLang ] && def.values[ defaultLang ].value ) || '';

		const translate = useCallback( async ( targetLang ) => {
			if ( ! sourceText || ! sourceText.trim() ) {
				setError( __( 'Source field is empty. Write the source-language version first.', 'triptych' ) );
				return;
			}
			setBusy( targetLang );
			setError( null );
			try {
				const r = await apiFetch( {
					path: '/triptych/v1/translate',
					method: 'POST',
					data: { from: defaultLang, to: targetLang, text: sourceText, field: fieldName },
				} );
				if ( ! r || typeof r.translated !== 'string' ) {
					throw new Error( 'Bad response' );
				}
				await apiFetch( {
					path: '/triptych/v1/save',
					method: 'POST',
					data: { post_id: postId, field: fieldName, lang: targetLang, value: r.translated },
				} );
				await refreshState();
			} catch ( err ) {
				const msg = err && err.message ? err.message : String( err );
				setError( sprintf( t( 'errorPrefix', 'Translation failed: %s' ), msg ) );
			} finally {
				setBusy( null );
			}
		}, [ postId, fieldName, defaultLang, sourceText, refreshState ] );

		const saveEdit = useCallback( async ( targetLang, value ) => {
			setBusy( targetLang );
			setError( null );
			try {
				await apiFetch( {
					path: '/triptych/v1/save',
					method: 'POST',
					data: { post_id: postId, field: fieldName, lang: targetLang, value: value },
				} );
				await refreshState();
			} catch ( err ) {
				const msg = err && err.message ? err.message : String( err );
				setError( sprintf( t( 'errorPrefix', 'Translation failed: %s' ), msg ) );
			} finally {
				setBusy( null );
			}
		}, [ postId, fieldName, refreshState ] );

		const langRows = Object.keys( languages )
			.filter( ( slug ) => slug !== defaultLang )
			.map( ( slug ) => {
				const langLabel = languages[ slug ];
				const v = def.values[ slug ] || { value: '', updated_at: null, has_value: false };
				const isOpen = openLang === slug;
				const isBusy = busy === slug;
				const status = v.has_value
					? sprintf( t( 'translated', 'Translated %s' ), fmtRelative( v.updated_at ) )
					: t( 'notTranslated', 'Not translated' );

				return el( 'div', { key: slug, className: 'triptych-lang-row' },
					el( 'div', { className: 'triptych-lang-row-head' },
						el( 'div', { className: 'triptych-lang-row-meta' },
							el( 'span', { className: 'triptych-lang-row-slug' }, slug.toUpperCase() ),
							el( 'span', { className: 'triptych-lang-row-label' }, langLabel ),
							el( 'span', { className: 'triptych-lang-row-status' + ( v.has_value ? '' : ' is-empty' ) }, status ),
						),
						el( 'div', { className: 'triptych-lang-row-actions' },
							el( Button, {
									isSmall: true,
									variant: 'secondary',
									disabled: isBusy,
									onClick: () => translate( slug ),
								},
								isBusy
									? el( Fragment, null, el( Spinner ), ' ', t( 'translatingState', 'Translating…' ) )
									: ( v.has_value
										? t( 'retranslateBtn', 'Re-translate' )
										: sprintf( t( 'translateBtn', 'Translate from %s' ), defaultLang.toUpperCase() ) )
							),
							el( Button, {
								isSmall: true,
								variant: 'tertiary',
								onClick: () => setOpenLang( isOpen ? null : slug ),
							}, isOpen ? t( 'doneLabel', 'Done' ) : t( 'editLabel', 'Edit translation' ) )
						)
					),
					isOpen && el( 'div', { className: 'triptych-lang-row-body' },
						def.type === 'textarea' || def.type === 'wysiwyg'
							? el( TextareaControl, {
									value: v.value,
									rows: 8,
									onChange: ( next ) => saveEdit( slug, next ),
									help: t( 'savedJustNow', 'Saved just now' ),
									__nextHasNoMarginBottom: true,
								} )
							: el( TextControl, {
									value: v.value,
									onChange: ( next ) => saveEdit( slug, next ),
									help: t( 'savedJustNow', 'Saved just now' ),
									__nextHasNoMarginBottom: true,
								} )
					)
				);
			} );

		return el( PanelBody,
			{ title: def.label, initialOpen: fieldName === 'post_title' || fieldName === 'post_content' },
			error && el( Notice, { status: 'error', isDismissible: true, onRemove: () => setError( null ) }, error ),
			el( 'div', { className: 'triptych-source-summary' },
				el( 'span', { className: 'triptych-source-summary-label' }, t( 'sourceLabel', 'Source' ) + ' (' + defaultLang.toUpperCase() + ')' ),
				el( 'span', { className: 'triptych-source-summary-len' },
					sourceText ? sourceText.length + ' chars' : '—'
				)
			),
			el( 'div', { className: 'triptych-lang-rows' }, langRows )
		);
	}

	function TriptychSidebar() {
		const { postId, postType, postTitle, postContent } = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			return {
				postId:      editor.getCurrentPostId(),
				postType:    editor.getCurrentPostType(),
				postTitle:   editor.getEditedPostAttribute( 'title' ),
				postContent: editor.getEditedPostAttribute( 'content' ),
			};
		}, [] );

		const [ state, setState ]   = useState( null );
		const [ error, setError ]   = useState( null );

		const refreshState = useCallback( async () => {
			if ( ! postId ) return;
			try {
				const data = await apiFetch( { path: '/triptych/v1/post/' + postId } );
				setState( data );
			} catch ( err ) {
				setError( err && err.message ? err.message : String( err ) );
			}
		}, [ postId ] );

		useEffect( () => { refreshState(); }, [ refreshState ] );

		// New posts haven't been saved yet → no postId. Prompt the editor to
		// save once before translation can attach to postmeta.
		if ( ! postId ) {
			return el( Fragment, null,
				el( PluginSidebarMoreMenuItem, { target: 'triptych-sidebar', icon: 'translation' },
					t( 'sidebarLabel', 'Triptych' )
				),
				el( PluginSidebar, {
						name: 'triptych-sidebar',
						title: t( 'sidebarLabel', 'Triptych' ),
						icon: 'translation',
					},
					el( PanelBody, null,
						el( Notice, { status: 'info', isDismissible: false }, t( 'savePostFirst', 'Save this post as a draft to start translating.' ) )
					)
				)
			);
		}

		if ( error ) {
			return el( PluginSidebar, {
					name: 'triptych-sidebar',
					title: t( 'sidebarLabel', 'Triptych' ),
					icon: 'translation',
				},
				el( PanelBody, null,
					el( Notice, { status: 'error', isDismissible: false }, error )
				)
			);
		}

		if ( ! state ) {
			return el( PluginSidebar, {
					name: 'triptych-sidebar',
					title: t( 'sidebarLabel', 'Triptych' ),
					icon: 'translation',
				},
				el( PanelBody, null, el( Spinner ) )
			);
		}

		const fields = state.fields || {};
		const liveSource = ( fieldName ) => {
			if ( fieldName === 'post_title' )   return postTitle;
			if ( fieldName === 'post_content' ) return postContent;
			return undefined;  // fall back to saved value in the panel
		};

		const fieldNodes = Object.keys( fields ).map( ( name ) =>
			el( FieldPanel, {
				key:           name,
				postId:        postId,
				fieldName:     name,
				def:           fields[ name ],
				defaultLang:   state.default,
				languages:     state.languages,
				sourceLive:    liveSource( name ),
				refreshState:  refreshState,
			} )
		);

		return el( Fragment, null,
			el( PluginSidebarMoreMenuItem, { target: 'triptych-sidebar', icon: 'translation' },
				t( 'sidebarLabel', 'Triptych' )
			),
			el( PluginSidebar, {
					name:  'triptych-sidebar',
					title: t( 'sidebarLabel', 'Triptych' ),
					icon:  'translation',
					className: 'triptych-sidebar',
				},
				fieldNodes
			)
		);
	}

	registerPlugin( 'triptych-sidebar', { render: TriptychSidebar, icon: 'translation' } );

} )( window.wp );
