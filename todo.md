# TODO / CURRENT STATUS

## Current phase

**Visitors + Gravity Forms integration: CLOSED**

Baseline: `df06821`

The current Visitors administration scope is considered complete and stable for the current phase.

## Closed

- [x] Visitor collection and visitor identity.
- [x] Sessions and pageviews.
- [x] Pageview URL inspection for an individual visitor.
- [x] Server-side search.
- [x] Server-side filters.
- [x] Server-side sorting.
- [x] Server-side pagination.
- [x] Visitor details.
- [x] Bot classification display.
- [x] Horizontal table scrolling.
- [x] CSV export action.
- [x] Gravity Forms integration.
- [x] Gravity Forms `vi_visitor_id` association.
- [x] Gravity Forms submission count in Visitors.
- [x] Sorting by Gravity Forms submission count.
- [x] Gravity Forms submission count in Visitor Details.
- [x] Coordinates removed from the main Visitors table.

## Deferred enhancements

These are intentionally outside the closed current phase.

- [ ] Display all Gravity Forms submissions for a selected visitor, including form name, submitted time and submitted values.
- [ ] Optimize Gravity Forms sorting fully at the database level for very large visitor volumes.
- [ ] Move CSV generation to a dedicated server-side export endpoint.
- [ ] Add dependent Country -> Region -> City filter behavior.
- [ ] Add the GeoIP resolved/unresolved UI filter.
- [ ] Revisit the specification requirement for a dedicated `vi_form_submissions` storage model versus using native Gravity Forms entries as the source of truth.
- [ ] Future HubSpot integration.

## Notes

The current phase is not blocked by the deferred items above. They are enhancement / architecture follow-up items.

The previous audit items in this file are superseded by the current implementation status and should not be treated as an active blocker list.
