/**
 * RankReady — AI Summary Gutenberg Block
 * Dynamic block with full style controls. No build step required.
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
	var PanelRow          = wp.components.PanelRow;
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

	var defaults = cfg.defaults || {
		label: 'Key Takeaways', showLabel: true, headingTag: 'h4'
	};

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

	var rnrdTransformOptions = [
		{ label: t( 'inherit', '— Inherit —' ), value: '' },
		{ label: 'None', value: 'none' },
		{ label: 'UPPERCASE', value: 'uppercase' },
		{ label: 'lowercase', value: 'lowercase' },
		{ label: 'Capitalize', value: 'capitalize' },
	];

	function decodeSummary( raw ) {
		if ( ! raw ) return { type: 'empty', data: [] };
		try {
			var parsed = JSON.parse( raw );
			if ( parsed && Array.isArray( parsed.bullets ) && parsed.bullets.length ) {
				return { type: 'bullets', data: parsed.bullets };
			}
		} catch ( e ) { /* not JSON */ }
		return { type: 'text', data: raw };
	}

	// Color label helper
	function colorControl( label, value, onChange ) {
		return el( BaseControl, { label: label, __nextHasNoMarginBottom: true },
			el( ColorPalette, {
				value: value || undefined,
				onChange: onChange,
				clearable: true,
			} )
		);
	}

	registerBlockType( 'rankready/ai-summary', {
		title:       t( 'summaryBlockTitle', 'AI Summary — RankReady' ),
		icon:        'editor-ul',
		category:    'rankready',
		description: t( 'summaryBlockDescription', 'Displays AI-generated key takeaways. Generate them from the RankReady panel.' ),
		keywords:    [ 'summary', 'takeaways', 'key points', 'ai', 'ai seo', 'llm', 'geo', 'answer engine', 'seo', 'rankready' ],

		attributes: {
			// Content
			label:               { type: 'string',  default: '' },
			showLabel:           { type: 'boolean', default: true },
			headingTag:          { type: 'string',  default: '' },
			// Box
			boxBgColor:          { type: 'string',  default: '' },
			boxBorderColor:      { type: 'string',  default: '' },
			boxBorderWidth:      { type: 'number',  default: 3 },
			boxBorderRadius:     { type: 'number',  default: 0 },
			boxBorderPosition:   { type: 'string',  default: 'left' },
			boxPadding:          { type: 'number',  default: 0 },
			// Label
			labelColor:          { type: 'string',  default: '' },
			labelFontSize:       { type: 'number',  default: 0 },
			labelFontFamily:     { type: 'string',  default: '' },
			labelFontWeight:     { type: 'string',  default: '' },
			labelLineHeight:     { type: 'number',  default: 0 },
			labelLetterSpacing:  { type: 'number',  default: 0 },
			labelTextTransform:  { type: 'string',  default: '' },
			// Bullets
			bulletColor:         { type: 'string',  default: '' },
			bulletFontSize:      { type: 'number',  default: 0 },
			bulletFontFamily:    { type: 'string',  default: '' },
			bulletFontWeight:    { type: 'string',  default: '' },
			bulletLineHeight:    { type: 'number',  default: 0 },
			bulletLetterSpacing: { type: 'number',  default: 0 },
			bulletSpacing:       { type: 'number',  default: 0 },
			bulletMarkerColor:   { type: 'string',  default: '' },
		},

		edit: function ( props ) {
			var attrs    = props.attributes;
			var setAttrs = props.setAttributes;

			var effectiveLabel    = attrs.label || defaults.label;
			var effectiveShowLabel = attrs.showLabel;
			var effectiveTag      = attrs.headingTag || defaults.headingTag;

			var _raw       = useState( '' );
			var raw        = _raw[0]; var setRaw = _raw[1];
			var _loading   = useState( false );
			var loading    = _loading[0]; var setLoading = _loading[1];
			var _error     = useState( '' );
			var error      = _error[0]; var setError = _error[1];
			var _hasKey    = useState( true );
			var hasKey     = _hasKey[0]; var setHasKey = _hasKey[1];
			var _cooldown  = useState( 0 );
			var cooldown   = _cooldown[0]; var setCooldown = _cooldown[1];

			var postId = useSelect( function ( select ) {
				return select( 'core/editor' ).getCurrentPostId();
			}, [] );

			useEffect( function () {
				if ( ! postId ) return;
				apiFetch( { path: '/rankready/v1/summary/' + postId } )
					.then( function ( data ) {
						setRaw( data.summary || '' );
						setHasKey( data.has_key !== false );
					} )
					.catch( function () {} );
			}, [ postId ] );

			useEffect( function () {
				if ( cooldown <= 0 ) return;
				var cooldownTimer = setTimeout( function () {
					setCooldown( function ( c ) { return c - 1; } );
				}, 1000 );
				return function () { clearTimeout( cooldownTimer ); };
			}, [ cooldown ] );

			var isSaving     = useSelect( function ( s ) { return s( 'core/editor' ).isSavingPost(); }, [] );
			var _wasSaving   = useState( false );
			var wasSaving    = _wasSaving[0]; var setWasSaving = _wasSaving[1];

			useEffect( function () {
				if ( wasSaving && ! isSaving && postId ) {
					setTimeout( function () {
						apiFetch( { path: '/rankready/v1/summary/' + postId } )
							.then( function ( data ) { setRaw( data.summary || '' ); } )
							.catch( function () {} );
					}, 4000 );
				}
				setWasSaving( isSaving );
			}, [ isSaving ] );

			function handleRegenerate() {
				if ( ! postId || loading || cooldown > 0 ) return;
				setLoading( true );
				setError( '' );
				apiFetch( { path: '/rankready/v1/regenerate/' + postId, method: 'POST' } )
					.then( function ( data ) {
						setRaw( data.summary || '' );
						setLoading( false );
						setCooldown( 60 );
					} )
					.catch( function ( err ) {
						var msg = ( err && err.message ) ? err.message : t( 'generationFailed', 'Generation failed.' );
						if ( err && err.code === 'rnrd_rate_limited' ) {
							var seconds = parseInt( msg.match( /\d+/ ) );
							if ( seconds ) setCooldown( seconds );
						}
						setError( msg );
						setLoading( false );
					} );
			}

			var summary    = decodeSummary( raw );
			var HeadingTag = effectiveTag || 'h4';
			// "Generate" the first time (no summary yet) → "Regenerate" once one
			// exists. Same logic for any post type / CPT — driven purely by
			// whether the REST summary came back empty.
			var hasSummary = summary.type !== 'empty';
			var regenLabel = loading
				? ( hasSummary ? t( 'regenerating', 'Regenerating…' ) : t( 'generating', 'Generating…' ) )
				: ( hasSummary ? t( 'regenerate', 'Regenerate' ) : t( 'generate', 'Generate' ) );

			// Build preview styles
			var boxStyle = {};
			if ( attrs.boxBgColor ) boxStyle.backgroundColor = attrs.boxBgColor;
			if ( attrs.boxBorderPosition === 'none' ) {
				boxStyle.border = 'none';
			} else if ( attrs.boxBorderPosition === 'all' ) {
				boxStyle.borderLeft = 'none';
				boxStyle.border = ( attrs.boxBorderWidth || 3 ) + 'px solid ' + ( attrs.boxBorderColor || 'currentColor' );
			} else {
				if ( attrs.boxBorderWidth ) boxStyle.borderLeftWidth = attrs.boxBorderWidth + 'px';
				if ( attrs.boxBorderColor ) boxStyle.borderLeftColor = attrs.boxBorderColor;
			}
			if ( attrs.boxBorderRadius ) boxStyle.borderRadius = attrs.boxBorderRadius + 'px';
			if ( attrs.boxPadding ) boxStyle.padding = attrs.boxPadding + 'px';
			if ( attrs.bulletMarkerColor ) boxStyle['--rnrd-marker-color'] = attrs.bulletMarkerColor;

			var labelStyle = {};
			if ( attrs.labelColor )          labelStyle.color = attrs.labelColor;
			if ( attrs.labelFontSize )       labelStyle.fontSize = attrs.labelFontSize + 'px';
			if ( attrs.labelFontFamily )     labelStyle.fontFamily = attrs.labelFontFamily;
			if ( attrs.labelFontWeight )     labelStyle.fontWeight = attrs.labelFontWeight;
			if ( attrs.labelLineHeight )     labelStyle.lineHeight = attrs.labelLineHeight;
			if ( attrs.labelLetterSpacing )  labelStyle.letterSpacing = attrs.labelLetterSpacing + 'px';
			if ( attrs.labelTextTransform )  labelStyle.textTransform = attrs.labelTextTransform;

			var bulletStyle = {};
			if ( attrs.bulletColor )          bulletStyle.color = attrs.bulletColor;
			if ( attrs.bulletFontSize )       bulletStyle.fontSize = attrs.bulletFontSize + 'px';
			if ( attrs.bulletFontFamily )     bulletStyle.fontFamily = attrs.bulletFontFamily;
			if ( attrs.bulletFontWeight )     bulletStyle.fontWeight = attrs.bulletFontWeight;
			if ( attrs.bulletLineHeight )     bulletStyle.lineHeight = attrs.bulletLineHeight;
			if ( attrs.bulletLetterSpacing )  bulletStyle.letterSpacing = attrs.bulletLetterSpacing + 'px';
			if ( attrs.bulletSpacing )        bulletStyle.marginBottom = attrs.bulletSpacing + 'px';

			var blockProps = useBlockProps( { className: 'rnrd-summary rnrd-editor-preview', style: boxStyle } );

			return el( Fragment, null,

				// ── Inspector Controls ─────────────────────────────────────────
				el( InspectorControls, null,

					// Panel: Summary Settings
					el( PanelBody, { title: t( 'panelSummarySettings', 'Summary Settings' ), initialOpen: true },
						el( ToggleControl, {
							label: t( 'showLabel', 'Show label' ),
							checked: effectiveShowLabel,
							onChange: function ( v ) { setAttrs( { showLabel: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						effectiveShowLabel && el( TextControl, {
							label: t( 'labelText', 'Label text' ),
							value: effectiveLabel,
							placeholder: defaults.label,
							onChange: function ( v ) { setAttrs( { label: v } ); },
							help: ! attrs.label ? t( 'usingGlobalDefault', '(Using global default)' ) : '',
							__nextHasNoMarginBottom: true,
						} ),

						effectiveShowLabel && el( SelectControl, {
							label: t( 'labelTag', 'Label tag' ),
							value: effectiveTag,
							options: [
								{ label: 'H2', value: 'h2' }, { label: 'H3', value: 'h3' },
								{ label: 'H4', value: 'h4' }, { label: 'H5', value: 'h5' },
								{ label: 'H6', value: 'h6' }, { label: 'P',  value: 'p'  },
							],
							onChange: function ( v ) { setAttrs( { headingTag: v } ); },
							help: ! attrs.headingTag ? tr( 'globalDefaultTag', '(Global default: %s)', defaults.headingTag ) : '',
							__nextHasNoMarginBottom: true,
						} ),

						el( 'div', { style: { marginTop: '12px' } },
							el( Button, {
								variant: 'secondary',
								isBusy: loading,
								disabled: loading || cooldown > 0 || ! postId || ! hasKey,
								onClick: handleRegenerate,
								style: { width: '100%', justifyContent: 'center' },
							}, regenLabel ),
							cooldown > 0 && el( 'p', { style: { color: '#757575', fontSize: '11px', margin: '8px 0 0' } },
								tr( 'regenIn', 'Regenerate available in %ds.', cooldown )
							)
						),

						! hasKey && el( 'p', { style: { color: '#d63638', fontSize: '12px', margin: '8px 0 0' } },
							t( 'apiKeyMissing', 'No API key yet. Add one under RankReady → Settings → API Keys.' )
						),

						el( 'p', { style: { color: '#757575', fontSize: '11px', margin: '8px 0 0', fontStyle: 'italic' } },
							t( 'summaryAutoGenerate', 'Click Generate in the RankReady panel to write the summary. RankReady Pro generates it automatically on publish.' )
						)
					),

					// Panel: Box Style
					el( PanelBody, { title: t( 'panelBoxStyle', 'Box Style' ), initialOpen: false },
						colorControl( t( 'bgColor', 'Background Color' ), attrs.boxBgColor, function ( v ) { setAttrs( { boxBgColor: v } ); } ),
						colorControl( t( 'borderColor', 'Border Color' ), attrs.boxBorderColor, function ( v ) { setAttrs( { boxBorderColor: v } ); } ),

						el( SelectControl, {
							label: t( 'borderPosition', 'Border Position' ),
							value: attrs.boxBorderPosition || 'left',
							options: [
								{ label: t( 'borderLeft', 'Left only' ), value: 'left' },
								{ label: t( 'borderAll', 'All sides' ), value: 'all' },
								{ label: t( 'borderNone', 'None' ), value: 'none' },
							],
							onChange: function ( v ) { setAttrs( { boxBorderPosition: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						el( RangeControl, {
							label: t( 'borderWidth', 'Border Width' ),
							value: attrs.boxBorderWidth || 3,
							onChange: function ( v ) { setAttrs( { boxBorderWidth: v } ); },
							min: 0, max: 10, step: 1,
							__nextHasNoMarginBottom: true,
						} ),

						el( RangeControl, {
							label: t( 'borderRadius', 'Border Radius' ),
							value: attrs.boxBorderRadius || 0,
							onChange: function ( v ) { setAttrs( { boxBorderRadius: v } ); },
							min: 0, max: 30, step: 1,
							__nextHasNoMarginBottom: true,
						} ),

						el( RangeControl, {
							label: t( 'padding', 'Padding' ),
							value: attrs.boxPadding || 0,
							onChange: function ( v ) { setAttrs( { boxPadding: v } ); },
							min: 0, max: 60, step: 2,
							help: t( 'zeroUseDefault', '0 = use default CSS' ),
							__nextHasNoMarginBottom: true,
						} )
					),

					// Panel: Label Style (full typography + global fonts)
					effectiveShowLabel && el( PanelBody, { title: t( 'panelLabelStyle', 'Label Style' ), initialOpen: false },
						colorControl( t( 'labelColor', 'Label Color' ), attrs.labelColor, function ( v ) { setAttrs( { labelColor: v } ); } ),

						el( SelectControl, {
							label: t( 'fontFamily', 'Font Family' ),
							help: t( 'fontFamilyHelp', 'Pulls from your theme.json fonts (Kadence, or any block theme). Leave blank to inherit from theme.' ),
							value: attrs.labelFontFamily || '',
							options: rnrdGlobalFontOptions(),
							onChange: function ( v ) { setAttrs( { labelFontFamily: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						el( SelectControl, {
							label: t( 'fontWeight', 'Font Weight' ),
							help: t( 'fontWeightHelp', 'Leave blank to inherit.' ),
							value: attrs.labelFontWeight || '',
							options: rnrdWeightOptions,
							onChange: function ( v ) { setAttrs( { labelFontWeight: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						el( RangeControl, {
							label: t( 'fontSizePx', 'Font Size (px)' ),
							value: attrs.labelFontSize || 0,
							onChange: function ( v ) { setAttrs( { labelFontSize: v } ); },
							min: 0, max: 48, step: 1,
							help: t( 'zeroInheritTheme', '0 = inherit from theme' ),
							__nextHasNoMarginBottom: true,
						} ),

						el( RangeControl, {
							label: t( 'lineHeight', 'Line Height' ),
							value: attrs.labelLineHeight || 0,
							onChange: function ( v ) { setAttrs( { labelLineHeight: v } ); },
							min: 0, max: 3, step: 0.05,
							help: t( 'zeroInherit', '0 = inherit' ),
							__nextHasNoMarginBottom: true,
						} ),

						el( RangeControl, {
							label: t( 'letterSpacingPx', 'Letter Spacing (px)' ),
							value: attrs.labelLetterSpacing || 0,
							onChange: function ( v ) { setAttrs( { labelLetterSpacing: v } ); },
							min: -2, max: 10, step: 0.1,
							help: t( 'zeroInherit', '0 = inherit' ),
							__nextHasNoMarginBottom: true,
						} ),

						el( SelectControl, {
							label: t( 'textTransform', 'Text Transform' ),
							value: attrs.labelTextTransform || '',
							options: rnrdTransformOptions,
							onChange: function ( v ) { setAttrs( { labelTextTransform: v } ); },
							__nextHasNoMarginBottom: true,
						} )
					),

					// Panel: Bullets Style (full typography + global fonts)
					el( PanelBody, { title: t( 'panelBulletsStyle', 'Bullets Style' ), initialOpen: false },
						colorControl( t( 'textColor', 'Text Color' ), attrs.bulletColor, function ( v ) { setAttrs( { bulletColor: v } ); } ),
						colorControl( t( 'markerColor', 'Marker Color' ), attrs.bulletMarkerColor, function ( v ) { setAttrs( { bulletMarkerColor: v } ); } ),

						el( SelectControl, {
							label: t( 'fontFamily', 'Font Family' ),
							help: t( 'fontFamilyHelpShort', 'Pulls from your theme.json fonts. Leave blank to inherit from theme.' ),
							value: attrs.bulletFontFamily || '',
							options: rnrdGlobalFontOptions(),
							onChange: function ( v ) { setAttrs( { bulletFontFamily: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						el( SelectControl, {
							label: t( 'fontWeight', 'Font Weight' ),
							value: attrs.bulletFontWeight || '',
							options: rnrdWeightOptions,
							onChange: function ( v ) { setAttrs( { bulletFontWeight: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						el( RangeControl, {
							label: t( 'fontSizePx', 'Font Size (px)' ),
							value: attrs.bulletFontSize || 0,
							onChange: function ( v ) { setAttrs( { bulletFontSize: v } ); },
							min: 0, max: 24, step: 1,
							help: t( 'zeroInherit', '0 = inherit' ),
							__nextHasNoMarginBottom: true,
						} ),

						el( RangeControl, {
							label: t( 'lineHeight', 'Line Height' ),
							value: attrs.bulletLineHeight || 0,
							onChange: function ( v ) { setAttrs( { bulletLineHeight: v } ); },
							min: 0, max: 3, step: 0.05,
							help: t( 'zeroInherit', '0 = inherit' ),
							__nextHasNoMarginBottom: true,
						} ),

						el( RangeControl, {
							label: t( 'letterSpacingPx', 'Letter Spacing (px)' ),
							value: attrs.bulletLetterSpacing || 0,
							onChange: function ( v ) { setAttrs( { bulletLetterSpacing: v } ); },
							min: -2, max: 10, step: 0.1,
							__nextHasNoMarginBottom: true,
						} ),

						el( RangeControl, {
							label: t( 'spaceBetweenPx', 'Space Between (px)' ),
							value: attrs.bulletSpacing || 0,
							onChange: function ( v ) { setAttrs( { bulletSpacing: v } ); },
							min: 0, max: 30, step: 1,
							help: t( 'zeroUseDefaultBullet', '0 = use default' ),
							__nextHasNoMarginBottom: true,
						} )
					)
				),

				// ── Editor Preview ────────────────────────────────────────────
				el( 'div', blockProps,

					effectiveShowLabel && el( HeadingTag, { className: 'rnrd-label', style: labelStyle }, effectiveLabel ),

					error && el( Notice, {
						status: 'error', isDismissible: true,
						onRemove: function () { setError( '' ); },
					}, error ),

					loading
						? el( 'div', { style: { display: 'flex', alignItems: 'center', gap: '8px' } },
							el( Spinner ), el( 'span', null, t( 'generatingEllipsis', 'Generating…' ) )
						)
						: summary.type === 'bullets'
							? el( 'ul', { className: 'rnrd-bullets' },
								summary.data.map( function ( b, i ) {
									return el( 'li', { className: 'rnrd-bullet', key: i, style: bulletStyle }, b );
								} )
							)
							: summary.type === 'text'
								? el( 'p', { className: 'rnrd-text', style: bulletStyle }, summary.data )
								: el( 'p', { style: { opacity: 0.5, fontStyle: 'italic', margin: 0 } },
									! hasKey
										? t( 'addApiKey', 'Add an API key under RankReady → Settings → API Keys.' )
										: t( 'summaryAfterPublish', 'No summary yet. Use Generate in the RankReady panel, as often as you like. Pro generates it for you on publish.' )
								)
				)
			);
		},

		save: function () { return null; },
	} );
} )();
