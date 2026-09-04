/**
 * The shared bundle, loaded on every page regardless of module - see
 * src/Core/Presentation/Front/templates/@layout.latte's `scripts` block.
 */
import naja from 'naja';

import './app.css';

naja.initialize({ history: true });
