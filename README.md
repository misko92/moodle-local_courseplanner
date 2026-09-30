# Course planner (`local_courseplanner`)

[![Moodle Plugin CI](https://github.com/misko92/moodle-local_courseplanner/actions/workflows/moodle-ci.yml/badge.svg?branch=main)](https://github.com/misko92/moodle-local_courseplanner/actions/workflows/moodle-ci.yml)

A Moodle local plugin that lets teachers define reusable course content once, build a week-by-week course calendar for the school year from that content, and publish a student-facing calendar view inside Moodle.

This is a fork of [Greg Mulcair's `local_coursecalendar`](https://github.com/GitHubGreg/moodle-local_coursecalendar) (v0.2.3). Changes from upstream:

- **Full-year calendars.** Calendars have a title (defaults to the school year, e.g. `2026-27`) instead of a year + Fall/Winter/Summer semester. Date rules are *First day of classes* / *Last day of classes*.
- **Terms (e.g. trimesters).** A year can be split into named terms; the grid shows a banner above each term, week numbers restart in each term, students get jump links, and the coverage check groups by term.
- **Fast full-year builder.** Cells, column headings, topics, dates and intro texts are edited in pop-up forms loaded on demand. The builder page for a 42-week year went from ~7 MB with ~500 rich-text editors to ~270 KB with none.
- **Bug fixes.** Topic drag-and-drop reordering never worked (missing drag handle, and the save failed with a database error); swapping two occupied builder cells failed; the recommended calendar could outrank the active one; "today" highlighting mis-dated weeks in school years spanning New Year; deleting a course left orphaned data; lecture/lab topics without content showed as blank cells.
- **Security fix.** Dates could be edited or deleted in other teachers' calendars by ID.
- **Restructured for easy upgrades.** Autoloaded classes instead of a 2,500-line `locallib.php`, Mustache templates instead of `html_writer` pages, dynamic forms, ES6 JavaScript, web services in `classes/external/`, the Hooks API, Bootstrap 5 markup, no hardcoded timezone.
- **Tests.** PHPUnit and Behat coverage, plus test data generators.

| | |
|---|---|
| **Plugin type** | Local (`local_courseplanner`) |
| **Requires** | Moodle 5.0+ (`2025041400`) |
| **Tested on** | Moodle 5.2 (`MOODLE_502_STABLE`), PHP 8.4 |
| **Maturity** | Alpha |
| **License** | [GPL v3 or later](https://www.gnu.org/licenses/gpl-3.0.html) |

---

## Table of Contents

1. [Installation](#installation)
2. [Getting Started](#getting-started)
3. [Capabilities and Roles](#capabilities-and-roles)
4. [Feature Guide](#feature-guide)
   - [Blueprints](#blueprints)
   - [Topics](#topics)
   - [Course Linking](#course-linking)
   - [Course Calendars](#course-calendars)
   - [The Builder](#the-builder)
   - [Academic Timeline Rules](#academic-timeline-rules)
   - [Auto-populate and Automation](#auto-populate-and-automation)
   - [Coverage Check](#coverage-check)
   - [Cleanup Actions](#cleanup-actions)
   - [Student View](#student-view)
   - [Intro Texts](#intro-texts)
   - [Embedded View](#embedded-view)
   - [Migration Helpers](#migration-helpers)
5. [Page Reference](#page-reference)
6. [Database Tables](#database-tables)
7. [Upgrading](#upgrading)
8. [Troubleshooting](#troubleshooting)
9. [Development](#development)
10. [Reporting Issues](#reporting-issues)
11. [License](#license)

---

## Installation

The repository root is the plugin directory itself, so the contents clone or extract directly into your Moodle's `local/courseplanner/` folder.

### Option A: Git clone (recommended)

From the root of your Moodle installation:

```bash
git clone https://github.com/misko92/moodle-local_courseplanner.git local/courseplanner
```

### Option B: Download a release zip

1. Download the latest release zip from the [Releases page](https://github.com/misko92/moodle-local_courseplanner/releases).
2. Extract it so the plugin files (`version.php`, `db/`, `lang/`, `amd/`, etc.) live directly at `<moodle>/local/courseplanner/`.

### Finish the install

1. Log in to Moodle as a site administrator.
2. Navigate to **Site administration > Notifications** to trigger the database install.
3. Confirm the plugin appears under **Site administration > Plugins > Local plugins**.

After installation, two course navigation links appear automatically for every course:

- **Course planner** (visible to editing teachers and managers)
- **Course calendar** (visible to all enrolled users, including students)

---

## Getting Started

The typical workflow for a new teacher is:

1. **Create a blueprint** -- Go to any course > More > Course planner > create a blueprint with a name (e.g. "Intro to CS").
2. **Add topics** -- Add your lecture topics, labs, eLessons, tests, and homework assignments to the blueprint.
3. **Link the course** -- Link the current course to the blueprint (manual or auto-link).
4. **Create a course calendar** -- Give it a title; it defaults to the current school year (e.g. `2026-27`).
5. **Set up the grid** -- Open the builder, configure header columns (day-of-week and Lecture/Lab mode), add week rows.
6. **Define academic timeline** -- Go to Manage Dates, add the first and last day of classes and exceptions (holidays, day swaps), then apply them to generate the week rows.
7. **Place topics** -- Use Auto-populate to place topics in the grid automatically, or place them manually via cell editors.
8. **Fill gaps** -- Use Fill Problem Sessions to populate empty lab cells, check coverage for missing topics.
9. **Publish** -- Students see the calendar via the Course calendar navigation link. Add Welcome/Links info via Course Info.

---

## Capabilities and Roles

| Capability | Default roles | Purpose |
|---|---|---|
| `local/courseplanner:manage` | Editing teacher, Manager | Full access to builder, topics, rules, automation, and course info |
| `local/courseplanner:view` | Guest, Student, Teacher, Editing teacher, Manager | Read-only access to the student calendar view |

Teachers can only see and edit blueprints they own. All builder pages enforce `require_login()` and `require_capability()` in the course context.

---

## Feature Guide

### Blueprints

A blueprint is a reusable topic library owned by a teacher. It represents a subject stream (e.g. "Mechanics", "Intro to CS") and stores the canonical set of topics used across years.

**Where:** Course planner (`manage.php`) > Blueprint library section.

**Actions:**
- **Create blueprint** -- Name, optional shortcode, and description.
- **Edit blueprint** -- Update name, shortcode, or description.
- **Archive/unarchive** -- Archiving hides a blueprint from the active list but preserves it.

Blueprints are per-teacher. Each teacher sees only their own blueprints.

### Topics

Topics are the individual content items within a blueprint. Each topic has a type that determines how it is placed and styled in the calendar.

**Where:** Course planner (`manage.php`) > Blueprint topics section.

**Topic types:**

| Type | Color | Auto-placement column | Description |
|---|---|---|---|
| `LECTURE` | Blue | Columns 1-3 (Lecture mode) | Standard lecture topics |
| `LAB` | Green | Columns 1-3 (Lab mode) | Laboratory sessions, placed after prerequisite lectures |
| `ELESSON` | Purple | Columns 1-3 (Lecture mode) | Online lessons students complete instead of attending class |
| `TEST` | Red | Columns 1-3 (Lecture mode) | Exams/tests, displayed with highlighted yellow styling |
| `HOMEWORK` | Orange | Column 4 | Assignments and problem sets |

**Actions:**
- **Create topic** / **Edit** -- Title, type, and HTML content, in a pop-up form.
- **Reorder** -- Drag a topic by its handle (or use the handle with the keyboard) to change placement order (affects auto-populate). Only available when the list isn't filtered by type.
- **Activate / Deactivate** -- Deactivated topics are hidden from the topic picker and auto-populate but remain in existing calendar placements.
- **Delete topic** -- Blocked if the topic is referenced by any calendar block.

**Live reference model:** Calendar blocks that reference a topic always render the *current* content from the blueprint. Editing a topic's content immediately updates every calendar that uses it.

### Course Linking

Each Moodle course links to exactly one blueprint to access its topic library.

**Where:** Course planner (`manage.php`) > Course to blueprint link section.

**Linking methods:**
- **Manual link** -- Select a blueprint from the dropdown and save.
- **Auto-link suggestion** -- The plugin can suggest a match based on course metadata. Click "Apply auto-link suggestion" to accept.

### Course Calendars

A course calendar is the per-course container for one run of the course (normally one school year) that holds the builder grid state.

**Where:** Course planner (`manage.php`) > Course calendars section.

**Actions:**
- **Create** -- A pop-up asks for a title (defaults to the current school year, e.g. `2026-27`), and optionally the first and last day of classes and up to three terms. With both days filled in, the week rows are generated straight away and you land in the builder. All of these can be changed later on the dates page.
- **Edit title** -- Update the display title.
- **Open builder** -- Navigate to the full grid builder page.
- **Activate / Deactivate** -- Only the active calendar is shown to students.
- **Delete** -- Permanently remove a calendar and all its blocks.

### The Builder

The builder is the main grid editing interface where teachers assemble the course calendar.

**Where:** `calendar.php` (accessed via "Open builder" from manage page).

**Grid structure:**

| Column | Purpose | Header config |
|---|---|---|
| 0 | Week labels | Read-only, generated by rules |
| 1-3 | Teaching days | Day-of-week + Lecture/Lab mode |
| 4 | Assignments | Fixed-purpose for homework |

**Header row (row 0):**
- Use the pencil on a column heading to rename it and, for columns 1-3, choose its weekday and Lecture/Lab mode.
- The bin icon deletes a column (from every row).

**Week rows:**
- Normally generated from the calendar's dates (see below).
- **Add week row** / **Remove last week row** for manual adjustments.

**Cell editing:** click **Edit cell** on any content cell to open its pop-up form:
- **Cell type** -- *Text block* (free-form HTML) or *Topic block* (a topic from the linked blueprint).
- **Cell heading** -- annotation displayed above the cell content.
- **Highlighted** / **Vertically centred**.
- **Clear this cell** -- removes the cell's content. Saving an empty text cell also clears it.

Cells showing a topic also have an **Edit** button that edits the shared blueprint topic, which changes it everywhere it is used.

Every edit is saved when you click *Save changes*.

**Drag and drop:** drag a content cell onto another to swap them.

**Page-level links:**
- **Manage Content** -- Back to topic management.
- **Manage Dates** -- The calendar's dates page.
- **Open Student Preview** -- Student view in a new tab.
- **Coverage Check** -- Topic coverage report.

### Academic Timeline Rules

Rules define the academic calendar structure: when classes start and end, holidays, day swaps, and other annotations.

**Where:** `rules.php` (accessed via "Manage Dates" from the builder).

**Rule types:**

| Type | Purpose | Fields used |
|---|---|---|
| `START` | First day of classes | Date, label |
| `END` | Last day of classes | Date, label |
| `TERM` | Start of a term, e.g. "Trimester 2" | Date, label (the term's name) |
| `NO_CLASS` | Holiday or break (no classes on this date) | Date, label, description |
| `DAY_SWAP` | Classes follow a different day's schedule | Date, label, from-day, to-day |
| `OTHER` | General annotation | Date, label, description |

**Actions:**
- **Add a new date** / **Edit** -- Type, date, label, description and (for day swaps) the days, in a pop-up form. Only one active first and last day of classes is allowed.
- **Activate / Deactivate** -- Deactivated dates are excluded from apply.
- **Delete** -- Permanently remove a date.
- **Apply Dates to Calendar** -- Runs the rule engine:
  1. Generates week labels from START to END.
  2. Adds "Classes begin" and "Last day of classes" annotations.
  3. Places NO_CLASS markers at the correct row/column based on the date and header day-of-week.
  4. Appends DAY_SWAP and OTHER annotations to week labels.
  5. Idempotent: previous rule-generated blocks are replaced; manual edits are preserved.

### Auto-populate and Automation

Automation buttons on the builder page handle bulk topic placement.

**Auto-populate** (green button):
1. Places LECTURE, ELESSON, and TEST topics into Lecture-mode columns (1-3) in blueprint sortorder, skipping occupied cells.
2. Places LAB topics into Lab-mode columns, positioned after their prerequisite lecture's row.
3. Places HOMEWORK topics sequentially into column 4.
4. TEST blocks get highlighted (yellow) and vertically-centred styling.
5. Existing content is never overwritten.

**Fill Problem Sessions** (green outline button):
- Scans all Lab-mode columns and inserts "Problem Session" TEXT blocks into empty cells with vertically-centred styling.

Both actions show a confirmation dialog before executing.

### Coverage Check

A report page showing topic placement completeness.

**Where:** `coverage.php` (accessed via "Coverage Check" from builder).

**Three sections:**
- **Found topics** (green) -- Topics placed in the grid with their position, day, and mode.
- **Missing topics** (red) -- Active topics that have no TOPIC block in the calendar.
- **Empty slots** (gray) -- Content cells (cols 1-3) with no block assigned.

### Cleanup Actions

Two destructive actions on the builder page, each with a confirmation dialog:

- **Delete Non-Header Blocks** (red outline) -- Removes *all* blocks below the header row, including week labels, text content, and topic placements. Use to completely reset the grid.
- **Delete Topics & Problem Sessions** (red outline) -- Removes TOPIC blocks and "Problem Session" TEXT blocks only, preserving week labels and other manually-entered text content. Useful for re-running auto-populate without losing timeline structure.

### Student View

The student-facing calendar shows the full grid with live topic content.

**Where:** `view.php` (accessed via the "Course calendar" navigation link).

**Features:**
- **Intro texts** -- optional left/right intro areas displayed above the calendar (if configured in the builder).
- **Today highlighting** -- The cell matching today's date (site timezone) is highlighted with a blue border.
- **Nearest-row highlighting** -- If today doesn't match a specific cell, the nearest week row is highlighted.
- **Auto-scroll** -- On page load, the browser scrolls to bring the current/nearest row into view.
- **Live content** -- TOPIC blocks render the latest `contenthtml` from the blueprint (not a snapshot).
- **External links** -- All links inside topic content and course info open in new tabs (`target="_blank"`).
- **Type badges** -- Color-coded badges indicate topic type (LECTURE, LAB, ELESSON, TEST, HOMEWORK).

### Intro Texts

Supplementary content displayed above the student calendar.

**Where:** the **Edit intro texts** button in the builder's "Intro texts (optional)" section.

**Fields:**
- **Intro text (left)** -- left-side introductory text.
- **Intro text (right)** -- right-side introductory text. Links automatically open in new tabs in the student view.

### Embedded View

A minimal-chrome version of the student calendar, designed for embedding in iframes.

**Where:** `embed.php` (`/local/courseplanner/embed.php?id=<course id>&calendarid=<calendar id>`).

**Differences from student view:**
- Uses Moodle's `embedded` page layout (no site navigation, header, or footer).
- Includes today-highlighting and auto-scroll.
- Ideal for embedding in external LMS pages or course homepages.

### Migration Helpers

Tools for importing existing calendar content into the plugin.

**Where:** `import_topics.php` (accessed via "Import Topics" on the manage page).

**Seed topics from HTML table:**
1. Paste an HTML table from an existing calendar (e.g. copied from a spreadsheet or web page).
2. Select the column layout: LLL (three Lecture columns), LLB (two Lecture + one Lab), LBL, or BLL.
3. Click "Import topics" -- the parser:
   - Detects topic types from content patterns (e.g. "Test" prefix = TEST, "Lab" prefix = LAB, "eLesson" = ELESSON).
   - Filters out non-topic content (Problem Session, College Closed, holidays).
   - Creates blueprint topics in order with full HTML content preserved.

**Delete all topics:**
- Red "Delete All Topics" button with confirmation dialog.
- Permanently removes all topics from the current blueprint.

---

## Page Reference

| Page | URL pattern | Capability required | Purpose |
|---|---|---|---|
| `manage.php` | `?id={courseid}` | `manage` | Blueprint, topic, and calendar management hub |
| `calendar.php` | `?id={courseid}&calendarid={id}` | `manage` | Grid builder |
| `rules.php` | `?id={courseid}&calendarid={id}` | `manage` | Academic timeline rules CRUD |
| `coverage.php` | `?id={courseid}&calendarid={id}` | `manage` | Topic coverage report |
| `import_topics.php` | `?id={courseid}&blueprintid={id}` | `manage` | HTML topic import |
| `view.php` | `?id={courseid}&calendarid={id}` | `view` | Student calendar view |
| `embed.php` | `?id={courseid}&calendarid={id}` | `view` | Iframe-friendly calendar |

---

## Database Tables

All tables are prefixed with `local_courseplanner_`.

| Table | Purpose |
|---|---|
| `blueprints` | Teacher-owned topic libraries |
| `topics` | Ordered topics within a blueprint |
| `courselink` | Links a course to one blueprint |
| `calendars` | Per-course calendar containers |
| `blocks` | Individual cells in the calendar grid |
| `rules` | Date rules (first/last day, holidays, swaps) |
| `ruleruns` | Traceability log of rule application runs |
| `courseinfo` | Welcome text and useful links per course |

Deleting a course deletes its calendars, blocks, rules, course link and course info. Blueprints belong to teachers and are kept.

---

## Upgrading

1. In your Moodle install, replace the contents of `local/courseplanner/` with the new version (e.g. `git pull` inside that directory, or re-extract a fresh release zip).
2. Navigate to **Site administration > Notifications**.
3. Moodle will detect the version change and run any necessary database upgrades.
4. Purge caches: **Site administration > Development > Purge all caches**.

---

## Troubleshooting

**Plugin pages return 403 / Access denied:**
- Ensure the user has the correct role in the course context. Editing teachers and managers get builder access; students get view-only access.

**Topics not appearing in the topic picker:**
- Verify the course is linked to a blueprint (manage page > Course to blueprint link).
- Check that topics are marked as active in the blueprint.

**Auto-populate places nothing:**
- Ensure there are week rows in the grid (use "Add week row").
- Ensure header columns 1-3 have their day and mode configured (Lecture or Lab).
- Check that topics exist and are active in the linked blueprint.

**Week labels not appearing after Apply Rules:**
- Ensure both a First day of classes and a Last day of classes date exist and are active.
- The end date must be after the start date.

**Styles look broken:**
- Purge all caches. The plugin stylesheet may be cached by Moodle's theme layer.
- Ensure the Moodle theme is Boost-based (Bootstrap 5).

---

## Development

Run the tests from the Moodle root:

```bash
vendor/bin/phpunit --testsuite local_courseplanner_testsuite
vendor/bin/behat --config <behat_dataroot>/behatrun/behat/behat.yml --tags @local_courseplanner
```

Behat named pages: `I am on the "<course shortname>" "local_courseplanner > Setup" page` (also `Student view`), and `I am on the "<calendar title>" "local_courseplanner > Builder" page` (also `Dates`, `Coverage`, `Preview`). Data generators: `local_courseplanner > blueprints`, `topics`, `calendars` (with optional `startdate`/`enddate`).

After changing `amd/src/`, rebuild with `npx grunt amd` from the plugin directory.

### Code layout

| Path | What's there |
|---|---|
| `*.php` (pages) | Thin controllers: check access, handle POSTed actions by calling `classes/local`, render a template. |
| `classes/local/` | Domain logic: `blueprints`, `topics`, `course_link`, `calendars`, `course_info`, `grid`, `timeline` (dates and applying them), `populate` (auto-populate and coverage), `importer`, `tours`, `hook_callbacks`. |
| `classes/output/` | Template data for the pages and the shared `calendar_grid` (builder, student view and embed). |
| `classes/form/` | Pop-up `dynamic_form`s: cell, column header, topic, date, intro texts. Each checks access itself. |
| `classes/external/` | Web services for drag-and-drop (swap cells, reorder topics). |
| `templates/` | Mustache templates; `action_form` is the one-button POST form used across pages. |
| `amd/src/` | ES modules: `modal_forms` (opens any `data-modalform` button's form), `builder` (cell drag-and-drop), `topicreorder`, `confirmaction`, `sections`, `calendar_view`, `showtour`. |
| `tours/` | User tours; bump a tour's version in `classes/local/tours.php` and call `tours::install()` in an upgrade step when you change one. |

## Reporting Issues

Please open an issue on [GitHub Issues](https://github.com/misko92/moodle-local_courseplanner/issues) with:

- Your Moodle version.
- The plugin version (see `version.php`).
- Steps to reproduce, and any relevant error messages or stack traces from the Moodle debug log.

---

## License

Released under the [GNU General Public License v3 or later](https://www.gnu.org/licenses/gpl-3.0.html). See [`LICENSE`](LICENSE) for the full text.
