Feature: Generate cache

  Scenario: Manage fp-super-cache via CLI
    Given a FP install

    When I try `fp super-cache status`
    Then STDERR should contain:
      """
      Error: FP Super Cache needs to be installed to use its FP-CLI commands.
      """

    When I run `fp plugin install fp-super-cache`
    Then STDOUT should contain:
      """
      Plugin installed successfully.
      """
    And the fp-content/plugins/fp-super-cache directory should exist

    When I try `fp super-cache enable`
    Then STDERR should contain:
      """
      Error: FP Super Cache needs to be activated to use its FP-CLI commands.
      """

    When I run `fp plugin activate fp-super-cache`
    And I run `fp super-cache enable`
    Then STDOUT should contain:
      """
      Success: The FP Super Cache is enabled.
      """
