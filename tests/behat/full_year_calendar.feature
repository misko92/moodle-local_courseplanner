@local @local_courseplanner
Feature: Teachers plan a full school year and students see it
  In order to publish a week-by-week plan for a full-year course
  As a teacher
  I need to create a calendar, apply its dates and have students see it

  Background:
    Given the following "courses" exist:
      | fullname      | shortname | format |
      | Chemistry 101 | CHEM101   | topics |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Student   | One      |
    And the following "course enrolments" exist:
      | user     | course  | role           |
      | teacher1 | CHEM101 | editingteacher |
      | student1 | CHEM101 | student        |
    And the following "local_courseplanner > blueprints" exist:
      | owner    | name           |
      | teacher1 | Chem blueprint |
    And the following "local_courseplanner > topics" exist:
      | blueprint      | title               |
      | Chem blueprint | Atoms and molecules |
      | Chem blueprint | Stoichiometry       |

  @javascript
  Scenario: Teacher links a blueprint and creates a calendar for the school year
    Given I log in as "teacher1"
    And I am on "Chemistry 101" course homepage
    When I navigate to "Course planner" in current page administration
    And I set the field "local-courseplanner-blueprintid" to "Chem blueprint"
    And I press "Set blueprint"
    And I click on "#local-courseplanner-createcalendar > summary" "css_element"
    And I set the field "local-courseplanner-title-new" to "2026-27"
    And I press "Create course calendar"
    Then I should see "Course calendar created."
    And I should see "2026-27"
    And I should not see "Semester"

  @javascript
  Scenario: Applying the year's dates builds a week row for every week and students can view it
    Given the following "local_courseplanner > calendars" exist:
      | course  | blueprint      | title   | startdate  | enddate    |
      | CHEM101 | Chem blueprint | 2026-27 | 2026-09-08 | 2027-06-25 |
    And I log in as "teacher1"
    When I am on the "2026-27" "local_courseplanner > Dates" page
    And I press "Apply Dates to Calendar"
    Then I should see "Week 42"
    And I should not see "Week 43"
    And I log out
    And I log in as "student1"
    And I am on the "CHEM101" "local_courseplanner > Student view" page
    Then I should see "2026-27"
    And I should see "Week 42"
