/**
 * Editor entry — registers the block so it appears in the inserter.
 * The actual frontend wizard lives in view.js; this file is
 * editor-only.
 */
import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import edit from './edit';
import './style.css';
import './editor.css';

registerBlockType( metadata.name, { edit } );
