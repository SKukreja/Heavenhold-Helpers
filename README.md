# Heavenhold Helpers

This plugin adds a custom database table to store user votes for items in the [Heavenhold](https://github.com/users/SKukreja/projects/1) project.

## Installation

1. Download the `heavenhold-helpers.php` file.
2. Place the file in your WordPress plugins directory.
3. Activate the plugin from the WordPress admin dashboard.

## Usage

The plugin registers a new database table called `build_likes` with the following columns:

- `vote_id` (BIGINT): The unique ID for each vote.
- `hero_id` (BIGINT): The ID of the hero associated with the vote.
- `item_id` (BIGINT): The ID of the item associated with the vote.
- `user_id` (BIGINT): The ID of the user who voted.
- `ip_address` (VARCHAR(15)): The IP address of the user who voted.
- `up_or_down` (TINYINT): The vote type: 1 for like, 0 for dislike.

The plugin also registers a custom WPGraphQL type called `ItemLikeDislikeCount` with the following fields:

- `itemId` (Int): The ID of the item.
- `likeCount` (Int): The total number of likes for the item.
- `dislikeCount` (Int): The total number of dislikes for the item.
- `userId` (Int): The ID of the user.
- `userVote` (String): The current user's vote status on the item: "like", "dislike", or "none".

You can access the likes and dislikes for a specific hero and user using the `itemsLikesByHero` query.