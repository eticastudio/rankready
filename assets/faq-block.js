/**
 * RankReady — FAQ Generator Gutenberg Block
 * Dynamic block with generate button and style controls. No build step required.
 */
( function () {
	'use strict';

	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var useState          = wp.element.useState;
	var useEffect         = wp.element.useEffect;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody         = wp.components.PanelBody;
	var ToggleControl     = wp.components.ToggleControl;
	var TextControl       = wp.components.TextControl;
	var SelectControl     = wp.components.SelectControl;
	var RangeControl      = wp.components.RangeControl;
	var ColorPalette      = wp.components.ColorPalette;
	var BaseControl       = wp.components.BaseControl;
	var Button            = wp.components.Button;
	var Notice            = wp.components.Notice;
	var Spinner           = wp.components.Spinner;
	var useSelect         = wp.data.useSelect;
	var apiFetch          = wp.apiFetch;

	var cfg  = window.rnrdBlockData || {};
	var i18n = window.rnrdI18n.bind( cfg.i18n || {} );
	var t    = i18n.t;
	var tr   = i18n.tr;

	// Editor-only: why this block renders nothing on the front end (feature or post type off).
	function rnrdFeatureHint( key, name, tabName ) {
		var f = ( cfg.features || {} )[ key ];
		if ( ! f ) return '';
		if ( ! f.on ) {
			return tr( 'featureOffHint', '%1$s is off. Turn it on in AI Content, then %2$s.', name, tabName );
		}
		var ed = wp.data.select( 'core/editor' );
		var type = ed && ed.getCurrentPostType ? ed.getCurrentPostType() : '';
		if ( type && 0 !== String( type ).indexOf( 'wp_' ) && f.types && ( ! f.types.length || -1 === f.types.indexOf( type ) ) ) {
			return tr( 'typeOffHint', '%1$s is not enabled for this post type. Turn it on in AI Content, then %2$s, Post types.', name, tabName );
		}
		return '';
	}

	// A numeric ID only: the Site Editor reports a template slug here, and no REST route exists for it.
	function rnrdRealPostId( select ) {
		var ed = select( 'core/editor' );
		if ( ! ed ) return 0;
		var id = ed.getCurrentPostId();
		var type = ed.getCurrentPostType ? ed.getCurrentPostType() : '';
		if ( ! id || ! /^\d+$/.test( String( id ) ) || ( type && 0 === String( type ).indexOf( 'wp_' ) ) ) return 0;
		return parseInt( id, 10 );
	}

	function colorControl( label, value, onChange ) {
		return el( BaseControl, { label: label, __nextHasNoMarginBottom: true },
			el( ColorPalette, {
				value: value || undefined,
				onChange: onChange,
				clearable: true,
			} )
		);
	}

	// ── Global fonts (theme.json → Nexter Theme / Nexter Blocks / Kadence / core) ─
	function rnrdGlobalFontOptions() {
		var opts = [ { label: t( 'themeDefault', '— Theme default —' ), value: '' } ];
		try {
			var settings = wp.data.select( 'core/block-editor' ).getSettings();
			var tree = settings && settings.__experimentalFeatures && settings.__experimentalFeatures.typography && settings.__experimentalFeatures.typography.fontFamilies;
			if ( tree ) {
				[ [ 'theme', 'Theme' ], [ 'custom', 'Custom' ], [ 'default', 'Default' ] ].forEach( function ( src ) {
					var list = tree[ src[0] ];
					if ( Array.isArray( list ) ) {
						list.forEach( function ( f ) {
							if ( f && f.fontFamily ) {
								opts.push( { label: src[1] + ' — ' + ( f.name || f.slug || f.fontFamily ), value: f.fontFamily } );
							}
						} );
					}
				} );
			}
			if ( opts.length === 1 && settings && Array.isArray( settings.fontFamilies ) ) {
				settings.fontFamilies.forEach( function ( f ) {
					if ( f && f.fontFamily ) opts.push( { label: f.name || f.fontFamily, value: f.fontFamily } );
				} );
			}
		} catch ( e ) { /* noop */ }
		return opts;
	}

	var rnrdWeightOptions = [
		{ label: t( 'inherit', '— Inherit —' ), value: '' },
		{ label: '100 Thin', value: '100' },
		{ label: '200 Extra Light', value: '200' },
		{ label: '300 Light', value: '300' },
		{ label: '400 Normal', value: '400' },
		{ label: '500 Medium', value: '500' },
		{ label: '600 Semi Bold', value: '600' },
		{ label: '700 Bold', value: '700' },
		{ label: '800 Extra Bold', value: '800' },
		{ label: '900 Black', value: '900' },
	];

	registerBlockType( 'rankready/faq', {
		title:       t( 'faqBlockTitle', 'FAQ — RankReady' ),
		icon:        'editor-help',
		category:    'rankready',
		description: t( 'faqBlockDescription', 'Display AI-generated FAQ with brand entity injection. Generate from the RankReady panel.' ),
		keywords:    [ 'faq', 'questions', 'qa', 'ai', 'ai seo', 'llm', 'geo', 'answer engine', 'seo', 'rankready', 'schema' ],

		attributes: {
			// Content
			showTitle:         { type: 'boolean', default: true },
			titleText:         { type: 'string',  default: 'Frequently Asked Questions' },
			headingTag:        { type: 'string',  default: 'h3' },
			showReviewed:      { type: 'boolean', default: true },
			keyword:           { type: 'string',  default: '' },
			// Box style
			boxBgColor:        { type: 'string',  default: '' },
			boxBorderColor:    { type: 'string',  default: '' },
			boxBorderWidth:    { type: 'number',  default: 0 },
			boxBorderRadius:   { type: 'number',  default: 0 },
			boxPadding:        { type: 'number',  default: 0 },
			// Question style
			questionColor:      { type: 'string',  default: '' },
			questionFontSize:   { type: 'number',  default: 0 },
			questionFontFamily: { type: 'string',  default: '' },
			questionFontWeight: { type: 'string',  default: '' },
			questionLineHeight: { type: 'number',  default: 0 },
			// Answer style
			answerColor:        { type: 'string',  default: '' },
			answerFontSize:     { type: 'number',  default: 0 },
			answerFontFamily:   { type: 'string',  default: '' },
			answerFontWeight:   { type: 'string',  default: '' },
			answerLineHeight:   { type: 'number',  default: 0 },
			// Divider
			dividerColor:       { type: 'string',  default: '' },
		},

		edit: function ( props ) {
			var attrs    = props.attributes;
			var setAttrs = props.setAttributes;

			var _faq       = useState( [] );
			var faq        = _faq[0]; var setFaq = _faq[1];
			var _loading   = useState( false );
			var loading    = _loading[0]; var setLoading = _loading[1];
			var _error     = useState( '' );
			var error      = _error[0]; var setError = _error[1];
			var _generated = useState( '' );
			var generated  = _generated[0]; var setGenerated = _generated[1];
			var _dragIndex = useState( -1 );
			var dragIndex  = _dragIndex[0]; var setDragIndex = _dragIndex[1];
			var _dragOver  = useState( -1 );
			var dragOver   = _dragOver[0]; var setDragOver = _dragOver[1];

			var postId = useSelect( rnrdRealPostId, [] );

			// Load existing FAQ on mount.
			useEffect( function () {
				if ( ! postId ) return;
				apiFetch( { path: '/rankready/v1/faq/get/' + postId } )
					.then( function ( data ) {
						if ( data && data.faq ) {
							setFaq( data.faq );
						}
						if ( data && data.generated ) {
							setGenerated( data.generated );
						}
						if ( data && data.keyword && ! attrs.keyword ) {
							setAttrs( { keyword: data.keyword } );
						}
					} )
					.catch( function () {} );
			}, [ postId ] );

			/*
			 * Reorder used to live inline in onDrop, with the auto-save written as
			 * `.catch( function () {} )`. That swallowed every failure: the list
			 * showed the new order, the database kept the old one, and the user was
			 * told nothing. It also had no keyboard path at all: the only handle was
			 * a dashicon with aria-hidden="true" and no tabIndex, so reordering FAQ
			 * items was mouse-only, a WCAG 2.1.1 failure on a shipped control.
			 *
			 * Both are fixed here, in one place: move() is what the drag handler and
			 * the two arrow buttons call, it reverts the local order when the save
			 * fails, and it says so.
			 */
			function moveFaqItem( from, to ) {
				if ( from === to || from < 0 || to < 0 || from >= faq.length || to >= faq.length ) return;
				var before    = [].concat( faq );
				var reordered = [].concat( faq );
				var moved     = reordered.splice( from, 1 )[0];
				reordered.splice( to, 0, moved );
				setFaq( reordered );
				if ( ! postId ) return;
				apiFetch( { path: '/rankready/v1/faq/save/' + postId, method: 'POST', data: { faq: reordered } } )
					.catch( function ( err ) {
						setFaq( before );
						setError( ( err && err.message )
							? err.message
							: t( 'faqReorderFailed', 'Could not save the new order. The previous order has been restored.' ) );
					} );
			}

			function handleGenerate() {
				if ( ! postId || loading ) return;
				setLoading( true );
				setError( '' );

				var body = {};
				if ( attrs.keyword ) {
					body.keyword = attrs.keyword;
				}

				apiFetch( {
					path: '/rankready/v1/faq/generate/' + postId,
					method: 'POST',
					data: body,
				} )
					.then( function ( data ) {
						if ( data && data.faq ) {
							setFaq( data.faq );
							setGenerated( t( 'justNow', 'Just now' ) );
						}
						setLoading( false );
					} )
					.catch( function ( err ) {
						var msg = ( err && err.message ) ? err.message : t( 'faqGenerationFailed', 'FAQ generation failed.' );
						setError( msg );
						setLoading( false );
					} );
			}

			// Build preview styles.
			var boxStyle = {};
			if ( attrs.boxBgColor ) boxStyle.backgroundColor = attrs.boxBgColor;
			if ( attrs.boxBorderColor && attrs.boxBorderWidth ) {
				boxStyle.border = attrs.boxBorderWidth + 'px solid ' + attrs.boxBorderColor;
			}
			if ( attrs.boxBorderRadius ) boxStyle.borderRadius = attrs.boxBorderRadius + 'px';
			if ( attrs.boxPadding ) boxStyle.padding = attrs.boxPadding + 'px';

			var questionStyle = {};
			if ( attrs.questionColor )      questionStyle.color = attrs.questionColor;
			if ( attrs.questionFontSize )   questionStyle.fontSize = attrs.questionFontSize + 'px';
			if ( attrs.questionFontFamily ) questionStyle.fontFamily = attrs.questionFontFamily;
			if ( attrs.questionFontWeight ) questionStyle.fontWeight = attrs.questionFontWeight;
			if ( attrs.questionLineHeight ) questionStyle.lineHeight = attrs.questionLineHeight;

			var answerStyle = {};
			if ( attrs.answerColor )      answerStyle.color = attrs.answerColor;
			if ( attrs.answerFontSize )   answerStyle.fontSize = attrs.answerFontSize + 'px';
			if ( attrs.answerFontFamily ) answerStyle.fontFamily = attrs.answerFontFamily;
			if ( attrs.answerFontWeight ) answerStyle.fontWeight = attrs.answerFontWeight;
			if ( attrs.answerLineHeight ) answerStyle.lineHeight = attrs.answerLineHeight;

			var dividerStyle = {};
			if ( attrs.dividerColor ) dividerStyle.borderBottomColor = attrs.dividerColor;

			var HeadingTag = attrs.headingTag || 'h3';
			var blockProps = useBlockProps( { className: 'rnrd-faq-wrapper rnrd-editor-preview', style: boxStyle } );

			return el( Fragment, null,

				// Inspector Controls
				el( InspectorControls, null,

					// Panel: FAQ Settings
					el( PanelBody, { title: t( 'panelFaqSettings', 'FAQ Settings' ), initialOpen: true },

						el( TextControl, {
							label: t( 'focusKeyword', 'Focus Keyword' ),
							value: attrs.keyword || '',
							placeholder: t( 'keywordPlaceholder', 'Auto-detected from Rank Math/Yoast' ),
							onChange: function ( v ) { setAttrs( { keyword: v } ); },
							help: t( 'keywordHelp', 'Leave empty to use SEO plugin focus keyword.' ),
							__nextHasNoMarginBottom: true,
						} ),

						el( ToggleControl, {
							label: t( 'showTitle', 'Show title' ),
							checked: attrs.showTitle,
							onChange: function ( v ) { setAttrs( { showTitle: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						attrs.showTitle && el( TextControl, {
							label: t( 'titleText', 'Title text' ),
							value: attrs.titleText || t( 'faqTitleDefault', 'Frequently Asked Questions' ),
							onChange: function ( v ) { setAttrs( { titleText: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						attrs.showTitle && el( SelectControl, {
							label: t( 'titleTag', 'Title tag' ),
							value: attrs.headingTag || 'h3',
							options: [
								{ label: 'H2', value: 'h2' }, { label: 'H3', value: 'h3' },
								{ label: 'H4', value: 'h4' }, { label: 'H5', value: 'h5' },
								{ label: 'H6', value: 'h6' },
							],
							onChange: function ( v ) { setAttrs( { headingTag: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						el( ToggleControl, {
							label: t( 'showLastReviewed', 'Show "Last reviewed" date' ),
							checked: attrs.showReviewed,
							onChange: function ( v ) { setAttrs( { showReviewed: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						el( 'div', { style: { marginTop: '16px' } },
							el( Button, {
								variant: 'primary',
								isBusy: loading,
								disabled: loading || ! postId,
								onClick: handleGenerate,
								style: { width: '100%', justifyContent: 'center' },
							}, ! postId ? t( 'openPostToGenerate', 'Open a post to generate' ) : loading ? t( 'generatingFaq', 'Generating FAQ…' ) : faq.length ? t( 'regenerateFaq', 'Regenerate FAQ' ) : t( 'generateFaq', 'Generate FAQ' ) )
						),

						generated && el( 'p', { style: { color: '#757575', fontSize: '11px', margin: '8px 0 0', fontStyle: 'italic' } },
							tr( 'lastGenerated', 'Last generated: %s', generated )
						)
					),

					// Panel: Box Style
					el( PanelBody, { title: t( 'panelBoxStyle', 'Box Style' ), initialOpen: false },
						colorControl( t( 'background', 'Background' ), attrs.boxBgColor, function ( v ) { setAttrs( { boxBgColor: v } ); } ),
						colorControl( t( 'borderColor', 'Border Color' ), attrs.boxBorderColor, function ( v ) { setAttrs( { boxBorderColor: v } ); } ),

						el( RangeControl, {
							label: t( 'borderWidth', 'Border Width' ),
							value: attrs.boxBorderWidth || 0,
							onChange: function ( v ) { setAttrs( { boxBorderWidth: v } ); },
							min: 0, max: 5, step: 1,
							__nextHasNoMarginBottom: true,
						} ),

						el( RangeControl, {
							label: t( 'borderRadius', 'Border Radius' ),
							value: attrs.boxBorderRadius || 0,
							onChange: function ( v ) { setAttrs( { boxBorderRadius: v } ); },
							min: 0, max: 20, step: 1,
							__nextHasNoMarginBottom: true,
						} ),

						el( RangeControl, {
							label: t( 'padding', 'Padding' ),
							value: attrs.boxPadding || 0,
							onChange: function ( v ) { setAttrs( { boxPadding: v } ); },
							min: 0, max: 60, step: 2,
							help: t( 'zeroNoPadding', '0 = no extra padding' ),
							__nextHasNoMarginBottom: true,
						} )
					),

					// Panel: Question Style (full typography + global fonts)
					el( PanelBody, { title: t( 'panelQuestionStyle', 'Question Style' ), initialOpen: false },
						colorControl( t( 'color', 'Color' ), attrs.questionColor, function ( v ) { setAttrs( { questionColor: v } ); } ),
						el( SelectControl, {
							label: t( 'fontFamily', 'Font Family' ),
							help: t( 'fontFamilyHelp', 'Pulls from your theme.json fonts (any block theme). Leave blank to inherit from theme.' ),
							value: attrs.questionFontFamily || '',
							options: rnrdGlobalFontOptions(),
							onChange: function ( v ) { setAttrs( { questionFontFamily: v } ); },
							__nextHasNoMarginBottom: true,
						} ),
						el( SelectControl, {
							label: t( 'fontWeight', 'Font Weight' ),
							value: attrs.questionFontWeight || '',
							options: rnrdWeightOptions,
							onChange: function ( v ) { setAttrs( { questionFontWeight: v } ); },
							__nextHasNoMarginBottom: true,
						} ),
						el( RangeControl, {
							label: t( 'fontSizePx', 'Font Size (px)' ),
							value: attrs.questionFontSize || 0,
							onChange: function ( v ) { setAttrs( { questionFontSize: v } ); },
							min: 0, max: 32, step: 1,
							help: t( 'zeroInherit', '0 = inherit' ),
							__nextHasNoMarginBottom: true,
						} ),
						el( RangeControl, {
							label: t( 'lineHeight', 'Line Height' ),
							value: attrs.questionLineHeight || 0,
							onChange: function ( v ) { setAttrs( { questionLineHeight: v } ); },
							min: 0, max: 3, step: 0.05,
							help: t( 'zeroInherit', '0 = inherit' ),
							__nextHasNoMarginBottom: true,
						} )
					),

					// Panel: Answer Style (full typography + global fonts)
					el( PanelBody, { title: t( 'panelAnswerStyle', 'Answer Style' ), initialOpen: false },
						colorControl( t( 'color', 'Color' ), attrs.answerColor, function ( v ) { setAttrs( { answerColor: v } ); } ),
						el( SelectControl, {
							label: t( 'fontFamily', 'Font Family' ),
							help: t( 'fontFamilyHelpShort', 'Pulls from your theme.json fonts. Leave blank to inherit from theme.' ),
							value: attrs.answerFontFamily || '',
							options: rnrdGlobalFontOptions(),
							onChange: function ( v ) { setAttrs( { answerFontFamily: v } ); },
							__nextHasNoMarginBottom: true,
						} ),
						el( SelectControl, {
							label: t( 'fontWeight', 'Font Weight' ),
							value: attrs.answerFontWeight || '',
							options: rnrdWeightOptions,
							onChange: function ( v ) { setAttrs( { answerFontWeight: v } ); },
							__nextHasNoMarginBottom: true,
						} ),
						el( RangeControl, {
							label: t( 'fontSizePx', 'Font Size (px)' ),
							value: attrs.answerFontSize || 0,
							onChange: function ( v ) { setAttrs( { answerFontSize: v } ); },
							min: 0, max: 24, step: 1,
							help: t( 'zeroInherit', '0 = inherit' ),
							__nextHasNoMarginBottom: true,
						} ),
						el( RangeControl, {
							label: t( 'lineHeight', 'Line Height' ),
							value: attrs.answerLineHeight || 0,
							onChange: function ( v ) { setAttrs( { answerLineHeight: v } ); },
							min: 0, max: 3, step: 0.05,
							help: t( 'zeroInherit', '0 = inherit' ),
							__nextHasNoMarginBottom: true,
						} ),
						colorControl( t( 'dividerColor', 'Divider Color' ), attrs.dividerColor, function ( v ) { setAttrs( { dividerColor: v } ); } )
					)
				),

				// Editor Preview
				el( 'div', blockProps,

					rnrdFeatureHint( 'faq', 'AI FAQ', 'AI FAQ' ) && el( Notice, { status: 'warning', isDismissible: false }, rnrdFeatureHint( 'faq', 'AI FAQ', 'AI FAQ' ) ),

					attrs.showTitle && el( HeadingTag, {
						className: 'rnrd-faq-title',
						style: attrs.questionColor ? { color: attrs.questionColor } : {},
					}, attrs.titleText || t( 'faqTitleDefault', 'Frequently Asked Questions' ) ),

					error && el( Notice, {
						status: 'error', isDismissible: true,
						onRemove: function () { setError( '' ); },
					}, error ),

					loading
						? el( 'div', { style: { display: 'flex', alignItems: 'center', gap: '8px', padding: '12px 0' } },
							el( Spinner ), el( 'span', null, t( 'generatingFaqLong', 'Generating FAQ…' ) )
						)
						: faq.length > 0
							? el( 'div', { className: 'rnrd-faq-list' },
								faq.map( function ( item, i ) {
									var itemStyle = Object.assign( {}, dividerStyle, {
										cursor: 'grab',
										opacity: dragIndex === i ? 0.4 : 1,
										borderTop: dragOver === i ? '2px solid #2271b1' : '2px solid transparent',
										transition: 'opacity 0.15s',
									} );
									return el( 'div', {
										className: 'rnrd-faq-item',
										key: i,
										style: itemStyle,
										draggable: true,
										onDragStart: function ( e ) { setDragIndex( i ); e.dataTransfer.effectAllowed = 'move'; },
										onDragOver: function ( e ) { e.preventDefault(); setDragOver( i ); },
										onDragLeave: function () { setDragOver( -1 ); },
										onDrop: function ( e ) {
											e.preventDefault();
											setDragOver( -1 );
											if ( dragIndex === i || dragIndex < 0 ) return;
											moveFaqItem( dragIndex, i );
											setDragIndex( -1 );
										},
										onDragEnd: function () { setDragIndex( -1 ); setDragOver( -1 ); },
									},
										el( 'div', { style: { display: 'flex', alignItems: 'flex-start', gap: '8px' } },
											// Dashicon, not `\u2261`. The editor already loads
											// dashicons, and the bare character rendered at a
											// different weight in every editor font.
											el( 'span', {
												className: 'dashicons dashicons-menu',
												'aria-hidden': 'true',
												style: { color: '#999', fontSize: '16px', width: '16px', height: '16px', cursor: 'grab', userSelect: 'none', lineHeight: '1.2' },
											} ),
											// The keyboard path. The dashicon above stays
											// decorative (it is the drag affordance); these are
											// real buttons, focusable and labelled, and they call
											// the same moveFaqItem() the drop handler calls.
											el( 'div', { style: { display: 'flex', flexDirection: 'column', gap: '2px' } },
												el( Button, {
													icon: 'arrow-up-alt2',
													label: t( 'faqMoveUp', 'Move question up' ),
													disabled: 0 === i,
													isSmall: true,
													onClick: function () { moveFaqItem( i, i - 1 ); },
												} ),
												el( Button, {
													icon: 'arrow-down-alt2',
													label: t( 'faqMoveDown', 'Move question down' ),
													disabled: i === faq.length - 1,
													isSmall: true,
													onClick: function () { moveFaqItem( i, i + 1 ); },
												} )
											),
											el( 'div', { style: { flex: 1 } },
												el( 'h4', {
													className: 'rnrd-faq-question',
													style: questionStyle,
												}, item.question ),
												el( 'p', {
													className: 'rnrd-faq-answer',
													style: answerStyle,
												}, item.answer )
											)
										)
									);
								} ),
								attrs.showReviewed && generated && el( 'p', {
									className: 'rnrd-faq-reviewed',
								}, tr( 'lastReviewed', 'Last reviewed: %s', generated ) )
							)
							: el( 'p', { style: { opacity: 0.5, fontStyle: 'italic', margin: 0, padding: '12px 0' } },
								t( 'clickGenerateFaq', 'Click "Generate FAQ" in the sidebar to create FAQ items for this post.' )
							)
				)
			);
		},

		save: function () { return null; },
	} );
} )();
