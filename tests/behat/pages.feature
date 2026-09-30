@local @local_courseplanner
Feature: Every course planner page works for teachers and students
  In order to plan and publish a course calendar
  As a teacher
  I need each planner page to show and save its content

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
      | blueprint      | title               | type     |
      | Chem blueprint | Atoms and molecules | LECTURE  |
      | Chem blueprint | Stoichiometry       | LECTURE  |
      | Chem blueprint | Titration lab       | LAB      |
      | Chem blueprint | Problem set 1       | HOMEWORK |
    And the following "local_courseplanner > calendars" exist:
      | course  | blueprint      | title   | startdate  | enddate    |
      | CHEM101 | Chem blueprint | 2026-27 | 2026-09-08 | 2027-06-25 |
    And the "2026-27" course planner calendar has been built

  Scenario: Teacher manages topics on the setup page
    Given I am on the "CHEM101" "local_courseplanner > Setup" page logged in as "teacher1"
    Then I should see "Chem blueprint"
    And I should see "2026-27"
    And I should see "Atoms and molecules"
    When I set the field "Topic title" to "Gas laws"
    And I press "Create topic"
    Then I should see "Topic created."
    And I should see "Gas laws"

  Scenario: Teacher adds a no-class date and re-applies the dates
    Given I am on the "2026-27" "local_courseplanner > Dates" page logged in as "teacher1"
    Then I should see "First day of classes"
    And I should see "Last day of classes"
    When I set the field "ruletype" in the "#local-courseplanner-createrule-form" "css_element" to "NO_CLASS"
    And I set the field "ruledate" in the "#local-courseplanner-createrule-form" "css_element" to "2026-10-12"
    And I set the field "label" in the "#local-courseplanner-createrule-form" "css_element" to "Thanksgiving Day"
    And I press "Add date"
    Then I should see "Date created."
    And I should see "Thanksgiving Day"
    When I press "Apply Dates to Calendar"
    Then I should see "Dates applied. 42 week(s) generated."
    And I should see "Thanksgiving Day"

  Scenario: Teacher sees the populated builder and the coverage report
    Given I am on the "2026-27" "local_courseplanner > Builder" page logged in as "teacher1"
    Then I should see "Atoms and molecules" in the "local-courseplanner-grid" "table"
    And I should see "Problem set 1" in the "local-courseplanner-grid" "table"
    And I should see "Week 42" in the "local-courseplanner-grid" "table"
    When I am on the "2026-27" "local_courseplanner > Coverage" page
    Then I should see "Found topics"
    And I should see "Stoichiometry"

  @javascript
  Scenario: Teacher edits cells, column headings and topics from the builder in pop-up forms
    Given I am on the "2026-27" "local_courseplanner > Builder" page logged in as "teacher1"
    When I click on "Edit cell" "button" in the "[data-cc-row='3'][data-cc-col='2']" "css_element"
    And I set the field "Content" to "Lab safety briefing"
    And I set the field "Highlighted" to "1"
    And I click on "Save changes" "button" in the ".modal-dialog" "css_element"
    Then I should see "Lab safety briefing" in the "[data-cc-row='3'][data-cc-col='2']" "css_element"
    When I click on "Edit column" "button" in the "[data-cc-row='0'][data-cc-col='3']" "css_element"
    And I set the field "Day of the week" to "Thursday"
    And I click on "Save changes" "button" in the ".modal-dialog" "css_element"
    Then I should see "Thursday" in the "[data-cc-row='0'][data-cc-col='3']" "css_element"
    When I click on "[data-cc-row='1'][data-cc-col='2'] [data-action='edit-topic']" "css_element"
    And I set the field "Topic title" to "Atoms, molecules and ions"
    And I click on "Save changes" "button" in the ".modal-dialog" "css_element"
    Then I should see "Atoms, molecules and ions" in the "local-courseplanner-grid" "table"
    When I click on "Edit intro texts" "button"
    And I set the field "Intro text (left)" to "Welcome to Chemistry"
    And I click on "Save changes" "button" in the ".modal-dialog" "css_element"
    And I am on the "CHEM101" "local_courseplanner > Student view" page
    Then I should see "Welcome to Chemistry"

  Scenario: Teacher imports topics from an HTML table
    Given I am on the "CHEM101" "local_courseplanner > Setup" page logged in as "teacher1"
    And I follow "Import Topics"
    When I set the field "importhtml" to "<table><tr><td>Week 1</td><td>Kinetics</td><td>Equilibrium</td><td>Acids and bases</td></tr></table>"
    And I press "Import topics"
    Then I should see "topic(s) created"
    And I am on the "CHEM101" "local_courseplanner > Setup" page
    And I should see "Kinetics"

  Scenario: Student sees the published calendar but not the teacher pages
    Given I am on the "CHEM101" "local_courseplanner > Student view" page logged in as "student1"
    Then I should see "2026-27"
    And I should see "Atoms and molecules"
    And I should see "Week 1"
    And I should not see "Edit cell"
