<?php
/**
 * Plugin Name: Heavenhold Helpers
 * Description: Plugin to create custom database tables with WPGraphQL
 * Version: 1.0
 * Author: Sumit Kukreja
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

register_activation_hook(__FILE__, 'create_build_likes_table');

function create_build_likes_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'build_likes';

    $charset_collate = $wpdb->get_charset_collate();

    // Updated SQL with up_or_down column
    $sql = "CREATE TABLE $table_name (
        vote_id BIGINT NOT NULL AUTO_INCREMENT,
        hero_id BIGINT NOT NULL,
        item_id BIGINT NOT NULL,
        user_id BIGINT,
        ip_address VARCHAR(15) NOT NULL,
        up_or_down TINYINT NOT NULL,
        PRIMARY KEY (vote_id),
        UNIQUE KEY unique_vote (hero_id, item_id, user_id, ip_address) 
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Uncomment this section to insert sample data
add_action('init', function() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'build_likes';

    // Sample data to be inserted
    $likes = [
        ['user_id' => 1, 'hero_id' => 5403, 'item_id' => 5911, 'ip_address' => '174.119.59.227', 'up_or_down' => 1],
        ['user_id' => 1, 'hero_id' => 5403, 'item_id' => 8908, 'ip_address' => '174.119.59.227', 'up_or_down' => 0],
    ];

    // Inserting the data
    foreach ($likes as $like) {
        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $like['user_id'],
                'hero_id' => $like['hero_id'],
                'item_id' => $like['item_id'],
                'ip_address' => $like['ip_address'],
                'up_or_down' => $like['up_or_down']
            ),
            array(
                '%d',
                '%d',
                '%d',
                '%s',
                '%d'
            )
        );
    }
});

// Hook into WPGraphQL as it builds the Schema
add_action('graphql_register_types', 'build_likes_table_register_types');

function build_likes_table_register_types() {
    // Register a new type for the like and dislike count
    register_graphql_object_type('ItemLikeDislikeCount', [
        'description' => __('Item ID, Like Count, Dislike Count, and Item Details', 'heavenhold-text'),
        'fields' => [
            'itemId' => [
                'type' => 'Int',
                'description' => __('The item ID', 'heavenhold-text'),
            ],
            'likeCount' => [
                'type' => 'Int',
                'description' => __('The total number of likes', 'heavenhold-text'),
            ],
            'dislikeCount' => [
                'type' => 'Int',
                'description' => __('The total number of dislikes', 'heavenhold-text'),
            ],
            'userId' => [
                'type' => 'Int',
                'description' => __('The ID of the user', 'heavenhold-text'),
            ],
            'userVote' => [
                'type' => 'String',
                'description' => __('The current user\'s vote status on the item: "like", "dislike", or "none"', 'heavenhold-text'),
            ],
            'item' => [
                'type' => 'Item',  // Assuming you have an Item GraphQL type
                'description' => __('The item details', 'heavenhold-text'),
                'resolve' => function($source, $args, $context, $info) {
                    // Fetch the item as a WP_Post object
                    $post = get_post($source['itemId']);
                    // Ensure it's wrapped as a WPGraphQL Post object
                    return !empty($post) ? new \WPGraphQL\Model\Post($post) : null;
                }
            ]
        ]
    ]);

    // Add a new field to the RootQuery for getting likes and dislikes by hero
    register_graphql_field('RootQuery', 'itemsLikesByHero', [
        'type' => ['list_of' => 'ItemLikeDislikeCount'],
        'description' => __('Get items and their total like and dislike counts for a specific hero and user', 'heavenhold-text'),
        'args' => [
            'heroId' => [
                'type' => 'Int',
                'description' => __('The ID of the hero', 'heavenhold-text'),
            ],
            'userId' => [
                'type' => 'Int',
                'description' => __('The ID of the user', 'heavenhold-text'),
            ],
            'ipAddress' => [
                'type' => 'String',
                'description' => __('The IP address of the user', 'heavenhold-text'),
                'defaultValue' => $_SERVER['REMOTE_ADDR'],
            ],
        ],
        'resolve' => function($root, $args, $context, $info) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'build_likes';
    
            $hero_id = $args['heroId'];
            $user_id = $args['userId'];
            $ip_address = sanitize_text_field($args['ipAddress']);
    
            // Query to get item_ids and their like and dislike counts for the specific hero and user
            $query = $wpdb->prepare(
                "SELECT item_id,
                        SUM(CASE WHEN up_or_down = 1 THEN 1 ELSE 0 END) as like_count,
                        SUM(CASE WHEN up_or_down = 0 THEN 1 ELSE 0 END) as dislike_count,
                        MAX(CASE WHEN (user_id = %d OR ip_address = %s) THEN up_or_down ELSE NULL END) as user_vote
                 FROM $table_name
                 WHERE hero_id = %d
                 GROUP BY item_id
                 ORDER BY like_count DESC",
                $user_id,
                $ip_address,
                $hero_id
            );
    
            // Execute the query and check the results
            $results = $wpdb->get_results($query);
    
            return array_map(function($row) use ($hero_id, $user_id) {
                return [
                    'itemId' => $row->item_id,
                    'likeCount' => intval($row->like_count),
                    'dislikeCount' => intval($row->dislike_count),
                    'userId' => $user_id,
                    'userVote' => is_null($row->user_vote) ? 'none' : ($row->user_vote == 1 ? 'like' : 'dislike'),
                ];
            }, $results);
        }
    ]);

    // GraphQL Query to Fetch User's Vote Status
    register_graphql_field('RootQuery', 'userVoteStatus', [
        'type' => 'String',
        'description' => __('Get the current user\'s vote status for a specific hero and item', 'heavenhold-text'),
        'args' => [
            'heroId' => [
                'type' => 'Int',
                'description' => __('The ID of the hero', 'heavenhold-text'),
            ],
            'itemId' => [
                'type' => 'Int',
                'description' => __('The ID of the item', 'heavenhold-text'),
            ],
            'userId' => [
                'type' => 'Int',
                'description' => __('The ID of the user', 'heavenhold-text'),
            ],
            'ipAddress' => [
                'type' => 'String',
                'description' => __('The IP address of the user', 'heavenhold-text'),
            ],
        ],
        'resolve' => function($root, $args, $context, $info) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'build_likes';

            $user_id = intval($args['userId']);
            $ip_address = sanitize_text_field($args['ipAddress']);
            $hero_id = intval($args['heroId']);
            $item_id = intval($args['itemId']);

            $vote_status = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT up_or_down FROM $table_name WHERE hero_id = %d AND item_id = %d AND (user_id = %d OR ip_address = %s)",
                    $hero_id,
                    $item_id,
                    $user_id,
                    $ip_address
                )
            );

            if ($vote_status === null) {
                return 'none';
            }

            return $vote_status == 1 ? 'like' : 'dislike';
        }
    ]);

    // Upvote Mutation with Conditional Logic
    register_graphql_mutation('upvoteItem', [
        'inputFields' => [
            'heroId' => [
                'type' => 'Int',
                'description' => __('The ID of the hero', 'heavenhold-text'),
            ],
            'itemId' => [
                'type' => 'Int',
                'description' => __('The ID of the item', 'heavenhold-text'),
            ],
            'userId' => [
                'type' => 'Int',
                'description' => __('The ID of the user', 'heavenhold-text'),
            ],
            'ipAddress' => [
                'type' => 'String',
                'description' => __('The IP address of the voter', 'heavenhold-text'),
            ],
        ],
        'outputFields' => [
            'success' => [
                'type' => 'Boolean',
                'description' => __('True if the vote was successful', 'heavenhold-text'),
            ],
            'currentVote' => [
                'type' => 'String',
                'description' => __('The current vote status after the operation', 'heavenhold-text'),
            ],
        ],
        'mutateAndGetPayload' => function($input, $context, $info) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'build_likes';

            // Prepare data for insertion
            $hero_id = intval($input['heroId']);
            $item_id = intval($input['itemId']);
            $user_id = intval($input['userId']);
            $ip_address = sanitize_text_field($input['ipAddress']);
            $up_or_down = 1;

            // Check existing vote
            $existing_vote = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT up_or_down FROM $table_name WHERE hero_id = %d AND item_id = %d AND (user_id = %d OR ip_address = %s)",
                    $hero_id,
                    $item_id,
                    $user_id,
                    $ip_address
                )
            );

            if ($existing_vote !== null) {
                // Update the existing vote
                $wpdb->update(
                    $table_name,
                    ['up_or_down' => $up_or_down],
                    [
                        'hero_id' => $hero_id,
                        'item_id' => $item_id,
                        'user_id' => $user_id,
                        'ip_address' => $ip_address
                    ],
                    ['%d'],
                    ['%d', '%d', '%d', '%s']
                );
            } else {
                // Insert a new vote
                $wpdb->insert(
                    $table_name,
                    [
                        'hero_id' => $hero_id,
                        'item_id' => $item_id,
                        'user_id' => $user_id,
                        'ip_address' => $ip_address,
                        'up_or_down' => $up_or_down
                    ],
                    ['%d', '%d', '%d', '%s', '%d']
                );
            }

            return ['success' => true, 'currentVote' => 'like'];
        }
    ]);

    // Downvote Mutation with Conditional Logic
    register_graphql_mutation('downvoteItem', [
        'inputFields' => [
            'heroId' => [
                'type' => 'Int',
                'description' => __('The ID of the hero', 'heavenhold-text'),
            ],
            'itemId' => [
                'type' => 'Int',
                'description' => __('The ID of the item', 'heavenhold-text'),
            ],
            'userId' => [
                'type' => 'Int',
                'description' => __('The ID of the user', 'heavenhold-text'),
            ],
            'ipAddress' => [
                'type' => 'String',
                'description' => __('The IP address of the voter', 'heavenhold-text'),
            ],
        ],
        'outputFields' => [
            'success' => [
                'type' => 'Boolean',
                'description' => __('True if the vote was successful', 'heavenhold-text'),
            ],
            'currentVote' => [
                'type' => 'String',
                'description' => __('The current vote status after the operation', 'heavenhold-text'),
            ],
        ],
        'mutateAndGetPayload' => function($input, $context, $info) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'build_likes';

            // Prepare data for insertion
            $hero_id = intval($input['heroId']);
            $item_id = intval($input['itemId']);
            $user_id = intval($input['userId']);
            $ip_address = sanitize_text_field($input['ipAddress']);
            $up_or_down = 0;

            // Check existing vote
            $existing_vote = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT up_or_down FROM $table_name WHERE hero_id = %d AND item_id = %d AND (user_id = %d OR ip_address = %s)",
                    $hero_id,
                    $item_id,
                    $user_id,
                    $ip_address
                )
            );

            if ($existing_vote !== null) {
                // Update the existing vote
                $wpdb->update(
                    $table_name,
                    ['up_or_down' => $up_or_down],
                    [
                        'hero_id' => $hero_id,
                        'item_id' => $item_id,
                        'user_id' => $user_id,
                        'ip_address' => $ip_address
                    ],
                    ['%d'],
                    ['%d', '%d', '%d', '%s']
                );
            } else {
                // Insert a new vote
                $wpdb->insert(
                    $table_name,
                    [
                        'hero_id' => $hero_id,
                        'item_id' => $item_id,
                        'user_id' => $user_id,
                        'ip_address' => $ip_address,
                        'up_or_down' => $up_or_down
                    ],
                    ['%d', '%d', '%d', '%s', '%d']
                );
            }

            return ['success' => true, 'currentVote' => 'dislike'];
        }
    ]);

    // Downvote Mutation with Conditional Logic
    register_graphql_mutation('downvoteItem', [
        'inputFields' => [
            'heroId' => [
                'type' => 'Int',
                'description' => __('The ID of the hero', 'heavenhold-text'),
            ],
            'itemId' => [
                'type' => 'Int',
                'description' => __('The ID of the item', 'heavenhold-text'),
            ],
            'ipAddress' => [
                'type' => 'String',
                'description' => __('The IP address of the voter', 'heavenhold-text'),
            ],
        ],
        'outputFields' => [
            'success' => [
                'type' => 'Boolean',
                'description' => __('True if the vote was successful', 'heavenhold-text'),
            ],
            'currentVote' => [
                'type' => 'String',
                'description' => __('The current vote status after the operation', 'heavenhold-text'),
            ],
        ],
        'mutateAndGetPayload' => function($input, $context, $info) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'build_likes';

            // Prepare data for insertion
            $hero_id = intval($input['heroId']);
            $item_id = intval($input['itemId']);
            $ip_address = sanitize_text_field($input['ipAddress']);
            $user_id = get_current_user_id(); // Can be 0 for not logged in
            $up_or_down = 0;

            // Check existing vote
            $existing_vote = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT up_or_down FROM $table_name WHERE hero_id = %d AND item_id = %d AND (user_id = %d OR ip_address = %s)",
                    $hero_id,
                    $item_id,
                    $user_id,
                    $ip_address
                )
            );

            if ($existing_vote !== null) {
                // Update the existing vote
                $wpdb->update(
                    $table_name,
                    ['up_or_down' => $up_or_down],
                    [
                        'hero_id' => $hero_id,
                        'item_id' => $item_id,
                        'user_id' => $user_id,
                        'ip_address' => $ip_address
                    ],
                    ['%d'],
                    ['%d', '%d', '%d', '%s']
                );
            } else {
                // Insert a new vote
                $wpdb->insert(
                    $table_name,
                    [
                        'hero_id' => $hero_id,
                        'item_id' => $item_id,
                        'user_id' => $user_id,
                        'ip_address' => $ip_address,
                        'up_or_down' => $up_or_down
                    ],
                    ['%d', '%d', '%d', '%s', '%d']
                );
            }

            return ['success' => true, 'currentVote' => 'dislike'];
        }
    ]);

    // Existing BuildLike type definition...
    register_graphql_object_type('BuildLike', [
        'description' => __('Likes per hero build item option', 'heavenhold-text'),
        'interfaces' => ['Node', 'DatabaseIdentifier'],
        'fields' => [
            'id' => [
                'type' => 'ID',
                'description' => __('The unique vote ID', 'heavenhold-text'),
                'resolve' => function ($source) {
                    return base64_encode('buildLike:' . $source->vote_id);
                }
            ],
            'heroDatabaseId' => [
                'type' => 'Int',
                'description' => __('The hero id', 'heavenhold-text'),
                'resolve' => function ($source) {
                    return $source->hero_id;
                }
            ],
            'itemDatabaseId' => [
                'type' => 'Int',
                'description' => __('The item id', 'heavenhold-text'),
                'resolve' => function ($source) {
                    return $source->item_id;
                }
            ],
            'userDatabaseId' => [
                'type' => 'Int',
                'description' => __('The user account associated with the like', 'heavenhold-text'),
                'resolve' => function ($source) {
                    return $source->user_id;
                }
            ],
            'ipAddress' => [
                'type' => 'String',
                'description' => __('The IP address of the user', 'heavenhold-text'),
                'resolve' => function ($source) {
                    return $source->ip_address;
                }
            ],
            'upOrDown' => [
                'type' => 'Int',
                'description' => __('1 for upvote, 0 for downvote', 'heavenhold-text'),
                'resolve' => function ($source) {
                    return $source->up_or_down;
                }
            ],
        ]
    ]);

    // Existing connection definitions...
    register_graphql_connection([
        'fromType' => 'RootQuery',
        'toType' => 'BuildLike',
        'fromFieldName' => 'buildLikes',
        'resolve' => function ($root, $args, $context, $info) {
            $resolver = new BuildLikesConnectionResolver($root, $args, $context, $info);
            return $resolver->get_connection();
        }
    ]);

    register_graphql_connection([
        'fromType' => 'BuildLike',
        'toType' => 'User',
        'fromFieldName' => 'user',
        'oneToOne' => true, // Corrected to true
        'resolve' => function ($root, $args, $context, $info) {
            $resolver = new \WPGraphQL\Data\Connection\UserConnectionResolver($root, $args, $context, $info);
            $resolver->set_query_arg('include', $root->user_id);
            return $resolver->one_to_one()->get_connection();
        }
    ]);

    register_graphql_connection([
        'fromType' => 'User',
        'toType' => 'BuildLike',
        'fromFieldName' => 'buildLikes',
        'resolve' => function ($root, $args, $context, $info) {
            $resolver = new BuildLikesConnectionResolver($root, $args, $context, $info);
            $resolver->set_query_arg('user_id', $root->databaseId);
            return $resolver->get_connection();
        }
    ]);

    // Register connection from BuildLike to Item using itemId
    register_graphql_connection([
        'fromType' => 'BuildLike',
        'toType' => 'Item',
        'fromFieldName' => 'item',
        'oneToOne' => true, // Define as a one-to-one connection
        'resolve' => function($root, $args, $context, $info) {
            $resolver = new \WPGraphQL\Data\Connection\PostObjectConnectionResolver($root, $args, $context, $info);
            $resolver->set_query_arg('include', $root->item_id);
            return $resolver->one_to_one()->get_connection();
        }
    ]);
}

add_action('graphql_init', function() {

    /**
     * Class BuildLikeLoader
     *
     * This is a custom loader that extends the WPGraphQL Abstract Data Loader.
     */
    class BuildLikeLoader extends \WPGraphQL\Data\Loader\AbstractDataLoader {

        /**
         * Given an array of one or more keys (ids) load the corresponding notifications
         *
         * @param array $keys Array of keys to identify nodes by
         *
         * @return array
         */
        public function loadKeys(array $keys): array {
            if (empty($keys)) {
                return [];
            }

            global $wpdb;

            // Prepare a SQL query to select rows that match the given IDs
            $table_name = $wpdb->prefix . 'build_likes';
            $ids = implode(', ', array_map('intval', $keys)); // Sanitize input
            $query = "SELECT * FROM $table_name WHERE vote_id IN ($ids) ORDER BY vote_id ASC";
            $results = $wpdb->get_results($query);

            if (empty($results)) {
                return [];
            }

            // Convert the array of likes to an associative array keyed by their IDs
            $buildLikesById = [];
            foreach ($results as $result) {
                // Ensure the like is returned with the BuildLike __typename
                $result->__typename = 'BuildLike';
                $buildLikesById[$result->vote_id] = $result;
            }

            // Create an ordered array based on the ordered IDs
            $orderedBuildLikes = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $buildLikesById)) {
                    $orderedBuildLikes[$key] = $buildLikesById[$key];
                }
            }

            return $orderedBuildLikes;
        }
    }

    // Add the likes loader to be used under the hood by WPGraphQL when loading nodes
    add_filter('graphql_data_loaders', function($loaders, $context) {
        $loaders['buildLike'] = new BuildLikeLoader($context);
        return $loaders;
    }, 10, 2);

    // Filter so nodes that have a __typename will return that typename
    add_filter('graphql_resolve_node_type', function($type, $node) {
        return $node->__typename ?? $type;
    }, 10, 2);
});

add_action('graphql_init', function() {

    class BuildLikesConnectionResolver extends \WPGraphQL\Data\Connection\AbstractConnectionResolver {

        // Tell WPGraphQL which Loader to use. We define the `buildLike` loader that we registered already.
        public function get_loader_name(): string {
            return 'buildLike';
        }

        // Get the arguments to pass to the query.
        // We're defaulting to an empty array as we're not supporting pagination/filtering/sorting in this example
        public function get_query_args(): array {
            return $this->args;
        }

        // Determine the query to run. Since we're interacting with a custom database Table, we
        // use $wpdb to execute a query against the table.
        // This is where logic needs to be mapped to account for any arguments the user inputs, such as pagination, filtering, sorting, etc.
        // For this example, we are only executing the most basic query without support for pagination, etc.
        // You could use an ORM to access data or whatever else you like here.
        public function get_query(): array {
            global $wpdb;

            // Simplified query to fetch IDs
            $ids_array = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT vote_id FROM ' . $wpdb->prefix . 'build_likes LIMIT 10'
                )
            );

            return !empty($ids_array) ? array_values(array_column($ids_array, 'vote_id')) : [];
        }

        // This determines how to get IDs. In our case, the query itself returns IDs
        // But sometimes queries, such as WP_Query might return an object with IDs as a property (i.e. $wp_query->posts )
        public function get_ids(): array {
            return $this->get_query();
        }

        // This allows for validation on the offset. If your data set needs specific data to determine the offset, you can validate that here.
        public function is_valid_offset($offset): bool {
            return true;
        }

        // This gives a chance to validate that the Model being resolved is valid.
        // We're skipping this and always saying the data is valid, but this is a good
        // place to add some validation before returning data
        public function is_valid_model($model): bool {
            return true;
        }

        // You can implement logic here to determine whether or not to execute.
        // for example, if the data is private you could set to false if the user is not logged in, etc
        public function should_execute(): bool {
            return true;
        }

    }

});
