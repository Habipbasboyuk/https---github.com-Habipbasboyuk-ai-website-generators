( function ( blocks, element, components, blockEditor, i18n, ServerSideRender ) {
  const el = element.createElement;
  const { __ } = i18n;
  const { InspectorControls } = blockEditor;
  const { PanelBody, TextControl, ToggleControl, SelectControl } = components;

  function withPreview( blockName, attrs, setAttrs ) {
    return el( ServerSideRender, { block: blockName, attributes: attrs } );
  }

  // Projects
  blocks.registerBlockType( 'agency-portal/projects', {
    title: 'Agency Portal: Projects',
    icon: 'portfolio',
    category: 'widgets',
    attributes: {
      title: { type: 'string', default: 'Your Projects' },
      layout: { type: 'string', default: 'table' },
      showStatus: { type: 'boolean', default: true },
      accent: { type: 'string', default: '' },
    },
    edit: ( props ) => {
      const { attributes, setAttributes } = props;
      return [
        el( InspectorControls, {},
          el( PanelBody, { title: 'Display', initialOpen: true },
            el( TextControl, {
              label: 'Title',
              value: attributes.title,
              onChange: (v) => setAttributes({ title: v })
            }),
            el( SelectControl, {
              label: 'Layout',
              value: attributes.layout,
              options: [
                { label: 'Table', value: 'table' },
                { label: 'List', value: 'list' },
              ],
              onChange: (v) => setAttributes({ layout: v })
            }),
            el( ToggleControl, {
              label: 'Show status',
              checked: attributes.showStatus,
              onChange: (v) => setAttributes({ showStatus: v })
            }),
            el( TextControl, {
              label: 'Accent (branding hook)',
              help: 'No styling enforced. Use this value in your theme/CSS if desired.',
              value: attributes.accent,
              onChange: (v) => setAttributes({ accent: v })
            }),
          )
        ),
        withPreview( 'agency-portal/projects', attributes, setAttributes ),
      ];
    },
    save: () => null, // dynamic
  } );

  // Tasks
  blocks.registerBlockType( 'agency-portal/tasks', {
    title: 'Agency Portal: Tasks',
    icon: 'list-view',
    category: 'widgets',
    attributes: {
      title: { type: 'string', default: 'Your Tasks' },
      layout: { type: 'string', default: 'table' },
      showProject: { type: 'boolean', default: true },
      showDates: { type: 'boolean', default: true },
      showStatus: { type: 'boolean', default: true },
      showOnlyClientVisible: { type: 'boolean', default: true },
    },
    edit: ( props ) => {
      const { attributes, setAttributes } = props;
      return [
        el( InspectorControls, {},
          el( PanelBody, { title: 'Display', initialOpen: true },
            el( TextControl, {
              label: 'Title',
              value: attributes.title,
              onChange: (v) => setAttributes({ title: v })
            }),
            el( SelectControl, {
              label: 'Layout',
              value: attributes.layout,
              options: [
                { label: 'Table', value: 'table' },
                { label: 'List', value: 'list' },
              ],
              onChange: (v) => setAttributes({ layout: v })
            }),
            el( ToggleControl, {
              label: 'Show project',
              checked: attributes.showProject,
              onChange: (v) => setAttributes({ showProject: v })
            }),
            el( ToggleControl, {
              label: 'Show dates',
              checked: attributes.showDates,
              onChange: (v) => setAttributes({ showDates: v })
            }),
            el( ToggleControl, {
              label: 'Show status',
              checked: attributes.showStatus,
              onChange: (v) => setAttributes({ showStatus: v })
            }),
            el( ToggleControl, {
              label: 'Only show client-visible tasks',
              checked: attributes.showOnlyClientVisible,
              onChange: (v) => setAttributes({ showOnlyClientVisible: v })
            }),
          )
        ),
        withPreview( 'agency-portal/tasks', attributes, setAttributes ),
      ];
    },
    save: () => null,
  } );

  // Task comments
  blocks.registerBlockType( 'agency-portal/task-comments', {
    title: 'Agency Portal: Task Comments',
    icon: 'admin-comments',
    category: 'widgets',
    attributes: {
      title: { type: 'string', default: 'Task Comments' },
      mode: { type: 'string', default: 'selected' }, // selected|queryvar
      taskId: { type: 'number', default: 0 },
    },
    edit: ( props ) => {
      const { attributes, setAttributes } = props;
      return [
        el( InspectorControls, {},
          el( PanelBody, { title: 'Display', initialOpen: true },
            el( TextControl, {
              label: 'Title',
              value: attributes.title,
              onChange: (v) => setAttributes({ title: v })
            }),
            el( SelectControl, {
              label: 'Mode',
              value: attributes.mode,
              options: [
                { label: 'Selected / fallback to query var', value: 'selected' },
                { label: 'Use query var (?ap_task=ID)', value: 'queryvar' },
              ],
              onChange: (v) => setAttributes({ mode: v })
            }),
            el( TextControl, {
              label: 'Task ID (optional)',
              help: 'If empty, uses ?ap_task=ID',
              value: attributes.taskId ? String(attributes.taskId) : '',
              onChange: (v) => setAttributes({ taskId: parseInt(v || '0', 10) })
            }),
          )
        ),
        withPreview( 'agency-portal/task-comments', attributes, setAttributes ),
      ];
    },
    save: () => null,
  } );

  // Client profile
  blocks.registerBlockType( 'agency-portal/client-profile', {
    title: 'Agency Portal: Client Profile',
    icon: 'id',
    category: 'widgets',
    attributes: {
      title: { type: 'string', default: 'Your Profile' },
      showContact: { type: 'boolean', default: true },
      showBilling: { type: 'boolean', default: false },
    },
    edit: ( props ) => {
      const { attributes, setAttributes } = props;
      return [
        el( InspectorControls, {},
          el( PanelBody, { title: 'Display', initialOpen: true },
            el( TextControl, {
              label: 'Title',
              value: attributes.title,
              onChange: (v) => setAttributes({ title: v })
            }),
            el( ToggleControl, {
              label: 'Show contact',
              checked: attributes.showContact,
              onChange: (v) => setAttributes({ showContact: v })
            }),
            el( ToggleControl, {
              label: 'Show billing',
              checked: attributes.showBilling,
              onChange: (v) => setAttributes({ showBilling: v })
            }),
          )
        ),
        withPreview( 'agency-portal/client-profile', attributes, setAttributes ),
      ];
    },
    save: () => null,
  } );

} )(
  window.wp.blocks,
  window.wp.element,
  window.wp.components,
  window.wp.blockEditor,
  window.wp.i18n,
  window.wp.serverSideRender
);