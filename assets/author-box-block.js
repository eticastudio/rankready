/**
 * RankReady — Author Box Gutenberg Block.
 *
 * Server-side rendered. Pulls author data via core user store. Font-family
 * dropdown reads theme.json globals so Nexter Theme / Nexter Blocks / Kadence /
 * any block theme's global fonts appear automatically.
 */
( function () {
	'use strict';

	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var useState          = wp.element.useState;
	var useEffect         = wp.element.useEffect;
	var useMemo           = wp.element.useMemo;
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
	var Notice            = wp.components.Notice;
	var useSelect         = wp.data.useSelect;

	var rrData = window.rnrdBlockData || { defaults: {}, users: [] };
	var i18n   = window.rnrdI18n.bind( rrData.i18n || {} );
	var t      = i18n.t;

	// Editor-only: why this block renders nothing on the front end (feature or post type off).
	function rnrdAuthorHint() {
		var f = ( rrData.features || {} ).author;
		if ( ! f ) return '';
		if ( ! f.on ) return t( 'authorOffHint', 'Author Box is off. Turn it on in AI Content, then Author Box.' );
		var ed = wp.data.select( 'core/editor' );
		var type = ed && ed.getCurrentPostType ? ed.getCurrentPostType() : '';
		if ( type && 0 !== String( type ).indexOf( 'wp_' ) && f.types && ( ! f.types.length || -1 === f.types.indexOf( type ) ) ) {
			return t( 'authorTypeOffHint', 'Author Box is not enabled for this post type. Turn it on in AI Content, then Author Box, Post types.' );
		}
		return '';
	}

	// ── Helpers ───────────────────────────────────────────────────────────────
	function colorControl( label, value, onChange, help ) {
		return el( BaseControl, { label: label, help: help || '', __nextHasNoMarginBottom: true },
			el( ColorPalette, { value: value || undefined, onChange: onChange, clearable: true } )
		);
	}

	/**
	 * Read all font families available from theme.json + site editor.
	 * Returns an array of {label, value} where value is the CSS font-family string.
	 * Source groups: theme (Nexter / Kadence / your block theme), default, custom.
	 */
	function useGlobalFonts() {
		return useMemo( function () {
			var opts = [ { label: t( 'themeDefault', '— Theme default —' ), value: '' } ];
			try {
				var settings = wp.data.select( 'core/block-editor' ).getSettings();
				var tree = settings && settings.__experimentalFeatures && settings.__experimentalFeatures.typography && settings.__experimentalFeatures.typography.fontFamilies;
				if ( tree ) {
					var sources = [
						{ key: 'theme',   label: 'Theme' },
						{ key: 'custom',  label: 'Custom' },
						{ key: 'default', label: 'Default' },
					];
					sources.forEach( function ( src ) {
						var list = tree[ src.key ];
						if ( Array.isArray( list ) ) {
							list.forEach( function ( f ) {
								if ( f && f.fontFamily ) {
									opts.push( {
										label: src.label + ' — ' + ( f.name || f.slug || f.fontFamily ),
										value: f.fontFamily,
									} );
								}
							} );
						}
					} );
				}
				// Fallback: legacy editor-font-families support.
				if ( opts.length === 1 && settings && Array.isArray( settings.fontFamilies ) ) {
					settings.fontFamilies.forEach( function ( f ) {
						if ( f && f.fontFamily ) {
							opts.push( { label: f.name || f.fontFamily, value: f.fontFamily } );
						}
					} );
				}
			} catch ( e ) {
				// noop — dropdown just shows default.
			}
			return opts;
		}, [] );
	}

	function fontWeightOptions() {
		return [
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
	}

	function typographyPanel( title, prefix, attrs, setAttrs, fontOptions, extra ) {
		return el( PanelBody, { title: title, initialOpen: false },
			colorControl( t( 'color', 'Color' ), attrs[ prefix + 'Color' ], function ( v ) {
				var patch = {}; patch[ prefix + 'Color' ] = v; setAttrs( patch );
			} ),
			el( SelectControl, {
				label: t( 'fontFamily', 'Font Family' ),
				help: t( 'fontFamilyHelp', 'Pulls from your theme.json fonts (any block theme). Leave blank to inherit from theme.' ),
				value: attrs[ prefix + 'FontFamily' ] || '',
				options: fontOptions,
				onChange: function ( v ) { var p = {}; p[ prefix + 'FontFamily' ] = v; setAttrs( p ); },
				__nextHasNoMarginBottom: true,
			} ),
			el( SelectControl, {
				label: t( 'fontWeight', 'Font Weight' ),
				help: t( 'fontWeightHelp', 'Leave blank to inherit.' ),
				value: attrs[ prefix + 'FontWeight' ] || '',
				options: fontWeightOptions(),
				onChange: function ( v ) { var p = {}; p[ prefix + 'FontWeight' ] = v; setAttrs( p ); },
				__nextHasNoMarginBottom: true,
			} ),
			el( RangeControl, {
				label: t( 'fontSizePx', 'Font Size (px)' ),
				help: t( 'zeroInheritTheme', '0 = inherit from theme' ),
				value: attrs[ prefix + 'FontSize' ] || 0,
				onChange: function ( v ) { var p = {}; p[ prefix + 'FontSize' ] = v; setAttrs( p ); },
				min: 0, max: 60, step: 1,
				__nextHasNoMarginBottom: true,
			} ),
			extra || null
		);
	}

	registerBlockType( 'rankready/author-box', {
		title:       t( 'authorBlockTitle', 'Author Box — RankReady' ),
		icon:        'businessperson',
		category:    'rankready',
		description: t( 'authorBlockDescription', 'Author box with name, job title, employer, bio and headshot from the user profile fields added by RankReady. A basic Person JSON-LD node for the same fields is published on author archive pages. Profile links, topics, credentials, memberships, awards and the extended Person schema are part of RankReady Pro.' ),
		keywords:    [ 'author', 'box', 'bio', 'rankready', 'eeat', 'e-e-a-t', 'person', 'schema', 'ai seo', 'llm', 'geo' ],
		supports:    { html: false, align: [ 'wide', 'full' ] },

		attributes: {
			authorSource:      { type: 'string',  default: 'post' },
			authorId:          { type: 'number',  default: 0 },
			layout:            { type: 'string',  default: 'card' },
			showHeading:       { type: 'boolean', default: true },
			headingText:       { type: 'string',  default: '' },
			headingTag:        { type: 'string',  default: '' },
			showHeadshot:      { type: 'boolean', default: true },
			showJobTitle:      { type: 'boolean', default: true },
			showEmployer:      { type: 'boolean', default: true },
			showYearsExp:      { type: 'boolean', default: true },
			showBio:           { type: 'boolean', default: true },
			showExpertise:     { type: 'boolean', default: true },
			showCredentials:   { type: 'boolean', default: true },
			showSocials:       { type: 'boolean', default: true },
			showReviewed:      { type: 'boolean', default: true },
			// Box.
			boxBgColor:        { type: 'string',  default: '' },
			boxBorderColor:    { type: 'string',  default: '' },
			boxBorderRadius:   { type: 'number',  default: 0 },
			boxPadding:        { type: 'number',  default: 0 },
			// Typography.
			headingColor:      { type: 'string',  default: '' },
			headingFontSize:   { type: 'number',  default: 0 },
			headingFontFamily: { type: 'string',  default: '' },
			headingFontWeight: { type: 'string',  default: '' },
			nameColor:         { type: 'string',  default: '' },
			nameFontSize:      { type: 'number',  default: 0 },
			nameFontFamily:    { type: 'string',  default: '' },
			nameFontWeight:    { type: 'string',  default: '' },
			metaColor:         { type: 'string',  default: '' },
			metaFontSize:      { type: 'number',  default: 0 },
			metaFontFamily:    { type: 'string',  default: '' },
			bioColor:          { type: 'string',  default: '' },
			bioFontSize:       { type: 'number',  default: 0 },
			bioFontFamily:     { type: 'string',  default: '' },
			bioLineHeight:     { type: 'number',  default: 0 },
			imageSize:         { type: 'number',  default: 0 },
			imageRadius:       { type: 'number',  default: 0 },
			socialColor:       { type: 'string',  default: '' },
			socialSize:        { type: 'number',  default: 0 },
		},

		edit: function ( props ) {
			var attrs    = props.attributes;
			var setAttrs = props.setAttributes;
			var fontOptions = useGlobalFonts();

			var postAuthorId = useSelect( function ( select ) {
				var ed = select( 'core/editor' );
				if ( ! ed ) return 0;
				return ed.getEditedPostAttribute( 'author' ) || 0;
			}, [] );

			var resolvedId = attrs.authorSource === 'specific' && attrs.authorId ? attrs.authorId : postAuthorId;

			var author = useSelect( function ( select ) {
				if ( ! resolvedId ) return null;
				return select( 'core' ).getUser( resolvedId );
			}, [ resolvedId ] );

			// Fetch all rnrd_author_* meta via core/user store.
			var meta = author && author.meta ? author.meta : {};

			var name = author ? author.name : '';
			var suffix = meta.rnrd_author_credentials_suffix || '';
			var jobTitle = meta.rnrd_author_job_title || '';
			var employer = meta.rnrd_author_employer || '';
			var bio = meta.rnrd_author_bio || ( author && author.description ) || '';
			var headshot = meta.rnrd_author_headshot || ( author && author.avatar_urls ? author.avatar_urls[ '96' ] : '' );
			var expertise = meta.rnrd_author_expertise || '';

			// Build preview styles from CSS vars.
			var previewStyle = {};
			if ( attrs.boxBgColor )      previewStyle.backgroundColor = attrs.boxBgColor;
			if ( attrs.boxBorderColor )  previewStyle.border = '1px solid ' + attrs.boxBorderColor;
			if ( attrs.boxBorderRadius ) previewStyle.borderRadius = attrs.boxBorderRadius + 'px';
			if ( attrs.boxPadding )      previewStyle.padding = attrs.boxPadding + 'px';

			var nameStyle = {};
			if ( attrs.nameColor )      nameStyle.color = attrs.nameColor;
			if ( attrs.nameFontSize )   nameStyle.fontSize = attrs.nameFontSize + 'px';
			if ( attrs.nameFontFamily ) nameStyle.fontFamily = attrs.nameFontFamily;
			if ( attrs.nameFontWeight ) nameStyle.fontWeight = attrs.nameFontWeight;

			var metaStyle = {};
			if ( attrs.metaColor )      metaStyle.color = attrs.metaColor;
			if ( attrs.metaFontSize )   metaStyle.fontSize = attrs.metaFontSize + 'px';
			if ( attrs.metaFontFamily ) metaStyle.fontFamily = attrs.metaFontFamily;

			var bioStyle = {};
			if ( attrs.bioColor )       bioStyle.color = attrs.bioColor;
			if ( attrs.bioFontSize )    bioStyle.fontSize = attrs.bioFontSize + 'px';
			if ( attrs.bioFontFamily )  bioStyle.fontFamily = attrs.bioFontFamily;
			if ( attrs.bioLineHeight )  bioStyle.lineHeight = attrs.bioLineHeight;

			var imgStyle = {};
			if ( attrs.imageSize ) {
				imgStyle.width  = attrs.imageSize + 'px';
				imgStyle.height = attrs.imageSize + 'px';
				imgStyle.objectFit = 'cover';
			}
			if ( attrs.imageRadius ) imgStyle.borderRadius = attrs.imageRadius + 'px';

			var headingStyle = {};
			if ( attrs.headingColor )      headingStyle.color = attrs.headingColor;
			if ( attrs.headingFontSize )   headingStyle.fontSize = attrs.headingFontSize + 'px';
			if ( attrs.headingFontFamily ) headingStyle.fontFamily = attrs.headingFontFamily;
			if ( attrs.headingFontWeight ) headingStyle.fontWeight = attrs.headingFontWeight;

			var blockProps = useBlockProps( {
				className: 'rnrd-author-box rnrd-ab-' + ( attrs.layout || 'card' ) + ' rnrd-ab-editor',
				style: previewStyle,
			} );

			var HeadingTag = attrs.headingTag || 'h3';
			var headingText = attrs.headingText || ( rrData.defaults && rrData.defaults.authorHeading ) || t( 'aboutTheAuthor', 'About the Author' );

			// Build users dropdown.
			var userOptions = [ { label: t( 'authorPostOption', '— Current post author —' ), value: 0 } ];
			if ( Array.isArray( rrData.users ) ) {
				rrData.users.forEach( function ( u ) {
					userOptions.push( { label: u.name + ' (#' + u.id + ')', value: u.id } );
				} );
			}

			return el( Fragment, null,

				// ── Inspector Controls ──────────────────────────────────
				el( InspectorControls, null,

					// Content
					el( PanelBody, { title: t( 'panelContent', 'Content' ), initialOpen: true },
						el( SelectControl, {
							label: t( 'authorSource', 'Author Source' ),
							help: t( 'authorSourceHelp', 'Defaults to the post author. Use "Specific" to pin a specific user (e.g. on a landing page).' ),
							value: attrs.authorSource || 'post',
							options: [
								{ label: t( 'authorPost', 'Current post author' ), value: 'post' },
								{ label: t( 'authorSpecific', 'Specific author' ), value: 'specific' },
							],
							onChange: function ( v ) { setAttrs( { authorSource: v } ); },
							__nextHasNoMarginBottom: true,
						} ),
						attrs.authorSource === 'specific' && el( SelectControl, {
							label: t( 'authorPick', 'Author' ),
							help: t( 'authorPickHelp', 'Pick the user whose RankReady Author Box data should render here.' ),
							value: attrs.authorId || 0,
							options: userOptions,
							onChange: function ( v ) { setAttrs( { authorId: parseInt( v, 10 ) || 0 } ); },
							__nextHasNoMarginBottom: true,
						} ),

						el( SelectControl, {
							label: t( 'layout', 'Layout' ),
							help: t( 'layoutHelp', 'Card = full end-of-article box. Compact = sidebar-ready. Inline = minimal byline row.' ),
							value: attrs.layout || 'card',
							options: [
								{ label: t( 'layoutCard', 'Card (full box)' ), value: 'card' },
								{ label: t( 'layoutCompact', 'Compact' ), value: 'compact' },
								{ label: t( 'layoutInline', 'Inline byline' ), value: 'inline' },
							],
							onChange: function ( v ) { setAttrs( { layout: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						attrs.layout !== 'inline' && el( ToggleControl, {
							label: t( 'showHeading', 'Show heading' ),
							checked: attrs.showHeading,
							onChange: function ( v ) { setAttrs( { showHeading: v } ); },
							help: t( 'showHeadingHelp', 'Headline above the box. Hidden automatically in inline layout.' ),
							__nextHasNoMarginBottom: true,
						} ),
						attrs.layout !== 'inline' && attrs.showHeading && el( TextControl, {
							label: t( 'headingText', 'Heading text' ),
							value: attrs.headingText,
							placeholder: headingText,
							help: t( 'headingBlankDefault', 'Leave blank to use site default.' ),
							onChange: function ( v ) { setAttrs( { headingText: v } ); },
							__nextHasNoMarginBottom: true,
						} ),
						attrs.layout !== 'inline' && attrs.showHeading && el( SelectControl, {
							label: t( 'headingTag', 'Heading tag' ),
							value: attrs.headingTag || 'h3',
							options: [
								{ label: 'H2', value: 'h2' }, { label: 'H3', value: 'h3' },
								{ label: 'H4', value: 'h4' }, { label: 'H5', value: 'h5' },
								{ label: 'H6', value: 'h6' }, { label: 'P',  value: 'p'  },
							],
							onChange: function ( v ) { setAttrs( { headingTag: v } ); },
							__nextHasNoMarginBottom: true,
						} ),
						el( 'hr', null )
					),

					// Visible Fields
					el( PanelBody, { title: t( 'panelVisibleFields', 'Visible Fields' ), initialOpen: false },
						[
							[ 'showHeadshot',    t( 'fieldHeadshot', 'Headshot' ) ],
							[ 'showJobTitle',    t( 'fieldJobTitle', 'Job Title' ) ],
							[ 'showEmployer',    t( 'fieldEmployer', 'Employer' ) ],
							[ 'showYearsExp',    t( 'fieldYearsExp', 'Years of Experience' ) ],
							[ 'showBio',         t( 'fieldBio', 'Bio' ) ],
							[ 'showExpertise',   t( 'fieldExpertise', 'Topics of Expertise' ) ],
							[ 'showCredentials', t( 'fieldCredentials', 'Credentials (Education + Certs)' ) ],
							[ 'showSocials',     t( 'fieldSocials', 'Social Links' ) ],
							[ 'showReviewed',    t( 'fieldReviewed', 'Reviewed-By / Last Reviewed' ) ],
						].map( function ( row, i ) {
							return el( ToggleControl, {
								key: i,
								label: row[1],
								checked: !! attrs[ row[0] ],
								onChange: function ( v ) { var p = {}; p[ row[0] ] = v; setAttrs( p ); },
								__nextHasNoMarginBottom: true,
							} );
						} )
					),

					// Box Style
					el( PanelBody, { title: t( 'panelBoxStyle', 'Box Style' ), initialOpen: false },
						colorControl( t( 'background', 'Background' ), attrs.boxBgColor, function ( v ) { setAttrs( { boxBgColor: v } ); } ),
						colorControl( t( 'borderColor', 'Border Color' ), attrs.boxBorderColor, function ( v ) { setAttrs( { boxBorderColor: v } ); } ),
						el( RangeControl, {
							label: t( 'borderRadiusPx', 'Border Radius (px)' ),
							value: attrs.boxBorderRadius || 0,
							onChange: function ( v ) { setAttrs( { boxBorderRadius: v } ); },
							min: 0, max: 40, step: 1,
							__nextHasNoMarginBottom: true,
						} ),
						el( RangeControl, {
							label: t( 'paddingPx', 'Padding (px)' ),
							value: attrs.boxPadding || 0,
							onChange: function ( v ) { setAttrs( { boxPadding: v } ); },
							min: 0, max: 60, step: 2,
							__nextHasNoMarginBottom: true,
						} )
					),

					// Typography panels (shared pattern).
					typographyPanel( t( 'typographyHeading', 'Heading Style' ), 'heading', attrs, setAttrs, fontOptions ),
					typographyPanel( t( 'typographyName', 'Name Style' ),    'name',    attrs, setAttrs, fontOptions ),
					typographyPanel( t( 'typographyMeta', 'Meta Style (job title / employer)' ), 'meta', attrs, setAttrs, fontOptions ),
					typographyPanel( t( 'typographyBio', 'Bio Style' ),     'bio',     attrs, setAttrs, fontOptions,
						el( RangeControl, {
							label: t( 'lineHeight', 'Line Height' ),
							help: t( 'zeroInheritTheme', '0 = inherit from theme' ),
							value: attrs.bioLineHeight || 0,
							onChange: function ( v ) { setAttrs( { bioLineHeight: v } ); },
							min: 0, max: 3, step: 0.05,
							__nextHasNoMarginBottom: true,
						} )
					),

					// Image Style
					el( PanelBody, { title: t( 'panelHeadshotStyle', 'Headshot Style' ), initialOpen: false },
						el( RangeControl, {
							label: t( 'imageSizePx', 'Size (px)' ),
							value: attrs.imageSize || 0,
							onChange: function ( v ) { setAttrs( { imageSize: v } ); },
							min: 0, max: 200, step: 2,
							help: t( 'imageSizeHelp', '0 = use layout default (card: 96px, compact: 64px)' ),
							__nextHasNoMarginBottom: true,
						} ),
						el( RangeControl, {
							label: t( 'borderRadiusPx', 'Border Radius (px)' ),
							value: attrs.imageRadius || 0,
							onChange: function ( v ) { setAttrs( { imageRadius: v } ); },
							min: 0, max: 100, step: 1,
							help: t( 'imageRadiusHelp', '100 = perfect circle' ),
							__nextHasNoMarginBottom: true,
						} )
					),

					// Social Style
					el( PanelBody, { title: t( 'panelSocialStyle', 'Social Style' ), initialOpen: false },
						colorControl( t( 'color', 'Color' ), attrs.socialColor, function ( v ) { setAttrs( { socialColor: v } ); } ),
						el( RangeControl, {
							label: t( 'fontSizePx', 'Font Size (px)' ),
							value: attrs.socialSize || 0,
							onChange: function ( v ) { setAttrs( { socialSize: v } ); },
							min: 0, max: 24, step: 1,
							help: t( 'zeroInherit', '0 = inherit' ),
							__nextHasNoMarginBottom: true,
						} )
					)
				),

				// ── Editor Preview ──────────────────────────────────────
				el( 'div', blockProps,
					rnrdAuthorHint() && el( Notice, { status: 'warning', isDismissible: false }, rnrdAuthorHint() ),
					! author && el( Notice, { status: 'info', isDismissible: false },
						resolvedId ? t( 'loadingAuthor', 'Loading author…' ) : t( 'authorFromPost', 'Shows the author of the post this block is on.' )
					),

					author && el( 'div', { className: 'rnrd-ab-preview' },
						attrs.showHeading && attrs.layout !== 'inline' && el( HeadingTag, { className: 'rnrd-ab-heading', style: headingStyle }, headingText ),
						el( 'div', { className: 'rnrd-ab-inner' },
							attrs.showHeadshot && headshot && el( 'div', { className: 'rnrd-ab-headshot' },
								el( 'img', { src: headshot, alt: name, style: imgStyle } )
							),
							el( 'div', { className: 'rnrd-ab-body' },
								el( 'div', { className: 'rnrd-ab-name', style: nameStyle },
									name + ( suffix ? ', ' + suffix : '' )
								),
								( attrs.showJobTitle && jobTitle ) || ( attrs.showEmployer && employer )
									? el( 'div', { className: 'rnrd-ab-meta', style: metaStyle },
										[ attrs.showJobTitle && jobTitle, attrs.showEmployer && employer ].filter( Boolean ).join( ' · ' )
									)
									: null,
								attrs.showBio && bio && attrs.layout !== 'inline' && el( 'p', { className: 'rnrd-ab-bio', style: bioStyle }, bio ),
								attrs.showExpertise && expertise && attrs.layout === 'card' && el( 'div', { className: 'rnrd-ab-expertise' },
									expertise.split( ',' ).map( function ( t, i ) {
										return el( 'span', { className: 'rnrd-ab-topic', key: i }, t.trim() );
									} )
								),
								! rnrdAuthorHint() && el( 'p', { style: { fontSize: '11px', opacity: 0.55, fontStyle: 'italic', margin: '8px 0 0' } },
									t( 'authorPreviewNote', 'Live render (socials, credentials, reviewed-by) appears on the front end.' )
								)
							)
						)
					)
				)
			);
		},

		save: function () { return null; },
	} );
} )();
