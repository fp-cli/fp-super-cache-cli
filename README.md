CLI interface for the FP Super Cache
--------------------------------------------------
This repository contains a [FP-CLI plugin](https://github.com/fp-cli/fp-cli)  for the [FP Super Cache Wordpress plugin](https://finpress.org/plugins/fp-super-cache/).  After installing this plugin, a Wordpress administrator will have access to a `fp super-cache` command

    $ fp super-cache
    usage: fp super-cache disable 
       or: fp super-cache enable 
       or: fp super-cache flush [--post_id=<post-id>] [--permalink=<permalink>]
       or: fp super-cache preload [--status] [--cancel]
       or: fp super-cache status 
    
    See 'fp help super-cache <command>' for more information on a specific command.

Installing
--------------------------------------------------
For instructions on installing this, and other, FP-CLI community packages, read the [Community Packages](https://github.com/fp-cli/fp-cli/wiki/Community-Packages) section of the FP-CLI Wiki.