# Hercule POS Admin Design Contract

## Product and audience
Hercule POS Admin is the operational control surface for the Hercule licensing server. It is used to manage licenses, customers, recovery requests, releases, administrators, devices, health and security-sensitive actions. The interface must remain fast to scan on desktop while being fully usable on phone-sized screens without horizontal page scrolling.

## Visual direction
The visual identity is **precision infrastructure**: dark, calm, technical and trustworthy rather than decorative. The signature element is the cyan Hercule signal line used for active navigation, focus and system-state emphasis. It should feel like an operations console, not a generic SaaS template.

### Core palette
- Deep background: `#070b12`
- Surface 1: `#0d141f`
- Surface 2: `#121c2a`
- Raised surface: `#172335`
- Primary text: `#f7f9fc`
- Hercule signal: `#48c5fa`
- Positive: `#43d59a`
- Warning: `#f6c453`
- Destructive: `#fb7185`

Runtime ownership: shared values are implemented in `public/admin/assets/css/premium-admin-refresh.css`, loaded after the legacy design layers from `style.css`. New shared visual decisions should extend that layer instead of adding another phase stylesheet.

## Typography
Use the existing application font stack. Hierarchy comes from weight, width and spacing rather than adding additional network font dependencies. Page titles are compact and strong; labels and metadata are quiet. Monospace is reserved for license keys, hashes and machine identifiers.

## Layout
- Desktop: fixed operational sidebar + bounded main content.
- Tablet/mobile: off-canvas sidebar plus persistent bottom navigation for primary destinations.
- Page content must never own accidental horizontal overflow.
- Tables may scroll horizontally inside their own table container.
- Cards become denser before they become smaller.
- Mobile toolbars stack; primary actions stay visible and full-width when needed.

## Component behavior
- Actions use native buttons/links and have visible hover, active, disabled and keyboard-focus states.
- Forms retain user input on errors and show correction text near the field.
- Search/filter regions must fit the viewport and wrap deliberately.
- Popovers and menus are bounded by the viewport and become internally scrollable when long.
- Sensitive confirmations use the shared app dialog in `admin-shell.js`; browser `alert`, `confirm` and `prompt` are not part of the UI contract.
- Destructive actions use the danger semantic tone only at the final action point.
- Toasts acknowledge outcomes but do not replace field-level errors.

## Responsive contract
- No horizontal document scrolling at 320px and above.
- At <=900px, shell controls collapse and page toolbars stack.
- At <=560px, stats become single-column and action/filter groups become single-column.
- Table containers retain horizontal scrolling rather than shrinking columns into unreadable widths.
- Safe-area insets are respected for mobile navigation.

## Accessibility
Target WCAG 2.2 AA.
- Do not disable browser zoom.
- Preserve visible focus for keyboard navigation.
- Keep semantic controls and accessible names.
- Modal dialogs restore focus after closing.
- Respect `prefers-reduced-motion` and forced-colors modes.
- Color is never the only status signal.

## Change discipline
Do not create a new global design-system stylesheet for each feature. Reuse this contract and the shared refinement layer. Page-specific CSS is allowed only for genuinely page-specific structure. Backend authorization and business rules remain authoritative and must never be weakened for UI convenience.
