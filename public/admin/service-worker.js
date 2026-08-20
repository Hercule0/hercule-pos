/* Compatibility entry point.
 * Older admin pages registered service-worker.js while the installed PWA uses sw.js.
 * Import the canonical worker so both paths execute exactly the same cache/push logic.
 */
importScripts('/public/admin/sw.js');
