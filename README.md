# visitor-intelligence

Independent, high-performance first-party telemetry and behavior analytics module for WordPress.

## Current Status

**Visitors administration and Gravity Forms integration: complete for the current implementation scope.**

Current stable baseline: `df06821`.

Implemented and verified:

- visitor collection and visitor identity;
- sessions and pageviews;
- pageview URL inspection for an individual visitor;
- server-side visitor search, filtering, sorting and pagination;
- visitor details view;
- bot classification data;
- synchronized horizontal table scrolling;
- CSV export from the Visitors interface;
- Gravity Forms integration through native Gravity Forms entries;
- association of Gravity Forms submissions with `visitor_id` through `vi_visitor_id`;
- Gravity Forms submission count in the Visitors table;
- sorting Visitors by Gravity Forms submission count in both directions;
- Gravity Forms submission count in Visitor Details;
- technical coordinates hidden from the main Visitors table.

Gravity Forms remains an integration/source layer. Its data is not merged into the canonical visitor record.

## Current Boundary

The current Visitors implementation is considered complete for this phase. Further work is enhancement work, not a blocker for the current Visitors functionality.

Known future enhancements:

- richer per-submission details and submitted-field inspection;
- fully database-side optimization of Gravity Forms sorting for very large visitor volumes;
- fully server-side CSV generation.

## Architecture

The plugin targets WordPress with PHP and MySQL and is structured around explicit repository, service, controller and integration boundaries.
