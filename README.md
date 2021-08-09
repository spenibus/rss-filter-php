rss-filter
==========

 - http://spenibus.net
 - https://github.com/spenibus/rss-filter-php
 - https://gitlab.com/spenibus/rss-filter-php
 - https://bitbucket.org/spenibus/rss-filter-php

This script aggregates and filters rss feeds to output only desired items as a
single feed.

-  Configuration files use the xml extension.
-  Configuration files must be placed in `./config/`
   -  example: `./config/customFeed.xml`
-  You can create multiple configuration files
-  A configuration file is loaded by calling the `config` parameter with the
   configuration filename without extension
   -  example: `rss-filter/?config=customFeed`
-  A configuration file sample is available at `./example.xml`
-  Configuration keywords are case sensitive, details below.

Configuration structure
-----------------------

````
<config>
    <title></title>
    <ruleSet>
        <source></source>
        <timeout></timeout>
        <userAgent></userAgent>
        <titleDuplicateRemove></titleDuplicateRemove>
        <linkDuplicateRemove></linkDuplicateRemove>
        <rules>
            <titleMatch></titleMatch>
            <titleMatchNot></titleMatchNot>
            <titleMatchMust></titleMatchMust>
            <before></before>
            <after></after>
        </rules>
    </ruleSet>
</config>
````

Configuration keywords
----------------------

 - `config`
   - root element
   - appears only once in entire document

 - `title`
   - sets the title of the output feed
   - appears only once within `config`

 - `ruleSet`
   - a configuration block
   - can occur multiple times within `config`

 - `source`
   - a url pointing to a rss feed
   - can occur multiple times within `ruleSet`

 - `timeout`
   - how long to wait for a source
   - this is any number above zero

 - `userAgent`
   - adds a user agent to the http request for the source

 - `titleDuplicateRemove`
   - value: true|false, default: false
   - appears only once within `ruleSet`
   - when this is set to `true`, if multiple items from sources share the same
     title, only the most recent is kept

 - `linkDuplicateRemove`
   - value: true|false, default: false
   - appears only once within `ruleSet`
   - when this is set to `true`, if multiple items from sources share the same
     link, only the most recent is kept

 - `rules`
   - a block of rules
   - can occur multiple times within `ruleSet`

 - `titleMatch`
   - a regular expression usable by PCRE (preg_*), ex: `/(foo|bar)/siu`
   - when at least one `titleMatch` matches the title of an item from one of
     the sources, the item is kept
   - this works like the logical operator `OR`

 - `titleMatchNot`
   - a regular expression usable by PCRE (preg_*), ex: `/(foo|bar)/siu`
   - when at least one `titleMatchNot` matches the title of an item from one of
     the sources, the item is discarded
   - this works like the logical operator `AND NOT`

 - `titleMatchMust`
   - a regular expression usable by PCRE (preg_*), ex: `/(foo|bar)/siu`
   - when at least one `titleMatchMust` doesn't match the title of an item from
     one of the sources, the item is discarded
   - this works like the logical operator `AND`

 - `descriptionMatch`
   - a regular expression usable by PCRE (preg_*), ex: `/(foo|bar)/siu`
   - when at least one `descriptionMatch` matches the description of an item from one of
     the sources, the item is kept
   - this works like the logical operator `OR`

 - `descriptionMatchNot`
   - a regular expression usable by PCRE (preg_*), ex: `/(foo|bar)/siu`
   - when at least one `descriptionMatchNot` matches the description of an item from one of
     the sources, the item is discarded
   - this works like the logical operator `AND NOT`

 - `descriptionMatchMust`
   - a regular expression usable by PCRE (preg_*), ex: `/(foo|bar)/siu`
   - when at least one `descriptionMatchMust` doesn't match the description of an item from
     one of the sources, the item is discarded
   - this works like the logical operator `AND`

 - `categoryMatch`
   - a regular expression usable by PCRE (preg_*), ex: `/(foo|bar)/siu`
   - when at least one `categoryMatch` matches any category of an item from one of
     the sources, the item is kept
   - this works like the logical operator `OR`

 - `categoryMatchNot`
   - a regular expression usable by PCRE (preg_*), ex: `/(foo|bar)/siu`
   - when at least one `categoryMatchNot` matches any category of an item from one of
     the sources, the item is discarded
   - this works like the logical operator `AND NOT`

 - `categoryMatchMust`
   - a regular expression usable by PCRE (preg_*), ex: `/(foo|bar)/siu`
   - when at least one `categoryMatchMust` doesn't match any category of an item from
     one of the sources, the item is discarded
   - this works like the logical operator `AND`

 - `before`
   - a string representing time than can be parsed by `strtotime()`
   - personally recommended format: `2014-12-31 23:59:59 +1200`
   - when an item pubDate is more recent than this, the item is discarded

 - `after`
   - a string representing time than can be parsed by `strtotime()`
   - personally recommended format: `2014-12-31 23:59:59 +1200`
   - when an item pubDate is older than this, the item is discarded

 - `olderThan`
   - a string representing a duration using a number followed by an optional quantifier, ex: `7d`
   - available quantifiers:
     - `s` for seconds
     - `m` for minutes
     - `h` for hours
     - `d` for days
   - default quantifier is `s` when omitted
   - when an item pubDate is more recent than the current time minus this duration, the item is discarded

 - `newerThan`
   - a string representing a duration using a number followed by an optional quantifier, ex: `7d`
   - available quantifiers:
     - `s` for seconds
     - `m` for minutes
     - `h` for hours
     - `d` for days
   - default quantifier is `s` when omitted
   - when an item pubDate is older than the current time minus this duration, the item is discarded

Notes
-----

 - `rules` blocks only apply to items coming from the `source` elements within the
   same `ruletSet` block

 - keywords within a `rules` block only apply to that block, this is important to
   remember when using multiple `rules` block because while one block can exclude
   some items, another block can still include them

 - multiple identically named keywords can appear in one `rules` block
   - multiple `*MatchMust` keywords within one `rules` block are implicitly AND-connected,
     i.e. **all matches must be true** in order for a feed item to be added to the output 
   - multiple `*MatchNot` keywords within one `rules` block are implicitly AND-NOT-connected,
     i.e. **all matches must be false** in order for a feed item to be added to the output
   - multiple `*Match` keywords within one `rules` block are implicitly OR-connected,
     i.e. **at least one match must be true** in order for a feed item to be added to the output

 - multiple `ruleSet` elements are implicitly OR-connected,
   i.e. **at least one `rules` block must be true** in order for a feed item to be added to the output

Examples
--------

````
<rules>
    <titleMatch>/red/</titleMatch>
</rules>
<rules>
    <titleMatchNot>/army/</titleMatchNot>
</rules>
````

The example above will return "red army" because the first `rules` block has
already added the item to the output when the second `rules` block is evaluated.

````
<rules>
    <titleMatch>/red/</titleMatch>
    <titleMatchNot>/army/</titleMatchNot>
</rules>
````

The example above will not return "red army".

````
<rules>
    <titleMatch>/red/</titleMatch>
    <titleMatchNot>/army/</titleMatchNot>
</rules>
<rules>
    <titleMatch>/.*/</titleMatch>
</rules>
````

The example above will also return "red army" because even though the first
`rules` block has discarded the item, the second one will match it.

It is possible to remove unused keywords, as in the example below:

````
<config>
   <ruleSet>
      <source></source>
      <rules>
         <titleMatch></titleMatch>
      </rules>
   </ruleSet>
</config>
````
