@format @format_simple
Feature: Simple format course display and navigation
  In order to use the Simple course format
  As a teacher
  I need to create and navigate a course using the Simple format

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format | numsections |
      | Course 1 | C1        | simple | 3           |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  Scenario: Teacher can view the course in Simple format
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I should see "Course 1"

  Scenario: View the default section 0 name
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    When I navigate to "Settings" in current page administration
    Then I should see "Simple format"

  Scenario: Teacher can view course with activities
    Given the following "activities" exist:
      | activity | name            | intro           | course | idnumber | section |
      | page     | Test Page       | Page content    | C1     | page1    | 1       |
    And I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I should see "Unit 1"

  Scenario: Student can view course content
    Given the following "activities" exist:
      | activity | name       | intro        | course | idnumber | section |
      | page     | Study Page | Page content | C1     | page1    | 1       |
    And I log in as "student1"
    When I am on "Course 1" course homepage
    Then I should see "Course 1"

  Scenario: Course format is set to Simple
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then I should see "Unit 1"
    And I should see "Unit 2"
    And I should see "Unit 3"

  @javascript
  Scenario: Continue records units and Finish submits the course
    Given I log in as "student1"
    And I am on "Course 1" course homepage
    When I click on "#simple-section-0 .simple-section-next" "css_element"
    Then "#simple-section-1.is-active" "css_element" should exist
    When I click on "#simple-section-1 .simple-section-next" "css_element"
    Then "#simple-section-2.is-active" "css_element" should exist
    And ".simple-nav-item[data-section='1'][data-unitread='true']" "css_element" should exist
    When I click on "#simple-section-2 .simple-section-next" "css_element"
    Then "#simple-section-3.is-active" "css_element" should exist
    When I click on "#simple-section-3 .simple-section-finish" "css_element"
    Then ".simple-completion-status" "css_element" should exist
    And ".simple-nav-item[data-section='3'][data-unitread='true']" "css_element" should exist

  @javascript
  Scenario: A course viewer who is not enrolled can continue between units
    Given I log in as "admin"
    And I am on "Course 1" course homepage
    When I click on "#simple-section-0 .simple-section-next" "css_element"
    Then "#simple-section-1.is-active" "css_element" should exist
    When I click on "#simple-section-1 .simple-section-next" "css_element"
    Then "#simple-section-2.is-active" "css_element" should exist
    And ".simple-nav-item[data-section='1'][data-unitread='false']" "css_element" should exist

  @javascript
  Scenario: Unit acknowledgement does not complete an unfinished activity
    Given the following "courses" exist:
      | fullname        | shortname | format | numsections | enablecompletion |
      | Progress course | PROGRESS  | simple | 2           | 1                |
    And the following "course enrolments" exist:
      | user     | course   | role    |
      | student1 | PROGRESS | student |
    And the following "activities" exist:
      | activity | name            | course   | section | completion |
      | forum    | Unfinished work | PROGRESS | 1       | 1          |
    And I log in as "student1"
    And I am on "Progress course" course homepage
    When I click on "#simple-section-0 .simple-section-next" "css_element"
    Then "#simple-section-1.is-active" "css_element" should exist
    When I click on "#simple-section-1 .simple-section-next" "css_element"
    Then "#simple-section-2.is-active" "css_element" should exist
    And ".simple-nav-item[data-section='1'][data-unitread='true'][data-percentage='50']" "css_element" should exist
    When I reload the page
    Then ".simple-nav-item[data-section='1'][data-unitread='true'][data-percentage='50']" "css_element" should exist

  @javascript
  Scenario: Navigation stays expanded when auto-collapse is disabled
    Given I log in as "student1"
    And I am on "Course 1" course homepage
    And I hover ".simple-content" "css_element"
    And I wait "4" seconds
    Then ".simple-nav.is-collapsed" "css_element" should not exist
    When I click on ".simple-nav-item[data-section='2']" "css_element"
    And I hover ".simple-content" "css_element"
    And I wait "4" seconds
    Then ".simple-nav.is-collapsed" "css_element" should not exist

  @javascript
  Scenario: Navigation collapses after the configured delay and expands on hover
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the field "Navigation auto-collapse delay" to "1 second"
    And I press "Save and display"
    And I log out
    And I log in as "student1"
    And I am on "Course 1" course homepage
    And I hover ".simple-content" "css_element"
    And I wait "2" seconds
    Then ".simple-nav.is-collapsed" "css_element" should exist
    When I hover ".simple-nav" "css_element"
    Then ".simple-nav.is-collapsed" "css_element" should not exist
    When I hover ".simple-content" "css_element"
    And I wait "2" seconds
    Then ".simple-nav.is-collapsed" "css_element" should exist

  @javascript
  Scenario: A course navigation delay can be changed
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the field "Navigation auto-collapse delay" to "Never"
    And I press "Save and display"
    Then "#format-simple-root[data-navcollapse='0']" "css_element" should exist
    When I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the field "Navigation auto-collapse delay" to "1 second"
    And I press "Save and display"
    Then "#format-simple-root[data-navcollapse='1']" "css_element" should exist

  @javascript
  Scenario: A blocks drawer button's tooltip does not stay up over the other button
    Given the following "blocks" exist:
      | blockname    | contextlevel | reference | pagetypepattern | defaultregion |
      | online_users | Course       | C1        | course-view-*   | side-pre      |
    And the following "user preferences" exist:
      | user     | preference               | value |
      | teacher1 | behat_keep_drawer_closed | 1     |
    And I log in as "teacher1"
    And I am on "Course 1" course homepage
    # The drawer's open and close buttons share a corner, so a tooltip left up on one covers
    # the other.
    And I hover ".drawer-right-toggle [data-toggler='drawers']" "css_element"
    And "//div[contains(@class, 'tooltip') and contains(@class, 'show')][contains(., 'Open block drawer')]" "xpath_element" should exist
    When I click on ".drawer-right-toggle [data-toggler='drawers']" "css_element"
    Then "//div[contains(@class, 'tooltip') and contains(@class, 'show')][contains(., 'Open block drawer')]" "xpath_element" should not exist
    And I hover ".drawer-right [data-action='closedrawer']" "css_element"
    And I click on ".drawer-right [data-action='closedrawer']" "css_element"
    And "//div[contains(@class, 'tooltip') and contains(@class, 'show')][contains(., 'Close block drawer')]" "xpath_element" should not exist
