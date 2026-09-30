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
  Scenario: Teacher creates a calendar for a school year split into trimesters
    Given I log in as "teacher1"
    And I am on "Chemistry 101" course homepage
    When I navigate to "Course planner" in current page administration
    And I set the field "local-courseplanner-blueprintid" to "Chem blueprint"
    And I press "Set blueprint"
    And I click on "#local-courseplanner-createcalendar" "css_element"
    And I set the following fields to these values:
      | Title                 | 2026-27     |
      | startdate[enabled]    | 1           |
      | startdate[day]        | 8           |
      | startdate[month]      | September   |
      | startdate[year]       | 2026        |
      | enddate[enabled]      | 1           |
      | enddate[day]          | 25          |
      | enddate[month]        | June        |
      | enddate[year]         | 2027        |
      | termdate1[enabled]    | 1           |
      | termdate1[day]        | 8           |
      | termdate1[month]      | September   |
      | termdate1[year]       | 2026        |
      | termdate2[enabled]    | 1           |
      | termdate2[day]        | 1           |
      | termdate2[month]      | December    |
      | termdate2[year]       | 2026        |
      | termdate3[enabled]    | 1           |
      | termdate3[day]        | 9           |
      | termdate3[month]      | March       |
      | termdate3[year]       | 2027        |
    And I click on "Save changes" "button" in the ".modal-dialog" "css_element"
    Then I should see "Calendar builder"
    And I should see "Trimester 1 · begins" in the "#local-courseplanner-term-1" "css_element"
    And I should see "Trimester 2 · begins" in the "#local-courseplanner-term-2" "css_element"
    And I should see "Week 1" in the "[data-cc-row='13'][data-cc-col='0']" "css_element"
    And I should see "Week 16" in the "[data-cc-row='42'][data-cc-col='0']" "css_element"
    And I should not see "Semester"
    When I follow "Coverage Check"
    Then I should see "Trimester 1"
    And I should see "Trimester 3"
    And I log out
    And I am on the "CHEM101" "local_courseplanner > Student view" page logged in as "student1"
    And I should see "Trimester 3" in the ".local-courseplanner-termnav" "css_element"
    And I click on "Trimester 3" "link" in the ".local-courseplanner-termnav" "css_element"
    And "#local-courseplanner-term-3" "css_element" should be visible

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
