<?php
function create_meta_votes_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'meta_votes';

    $charset_collate = $wpdb->get_charset_collate();

    // Updated SQL with up_or_down column
    $sql = "CREATE TABLE $table_name (
        vote_id BIGINT NOT NULL AUTO_INCREMENT,
        hero_id BIGINT NOT NULL,
        category_id BIGINT NOT NULL,
        user_id BIGINT,
        vote_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(15) NOT NULL,
        up_or_down TINYINT NOT NULL,
        PRIMARY KEY (vote_id),
        UNIQUE KEY unique_vote_user (hero_id, category_id, user_id),
        UNIQUE KEY unique_vote_ip (hero_id, category_id, ip_address)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Hook into WPGraphQL as it heros the Schema
add_action('graphql_register_types', 'meta_votes_table_register_types');

function meta_votes_table_register_types() {
    // Register a new type for the vote and downvote count
    register_graphql_object_type('MetaVoteCount', [
        'description' => __('Team ID, Vote Count, Downvote Count, and Team Details', 'heavenhold-text'),
        'fields' => [
            'heroId' => [
                'type' => 'Int',
                'description' => __('The hero ID', 'heavenhold-text'),
            ],
            'upvoteCount' => [
                'type' => 'Int',
                'description' => __('The total number of votes', 'heavenhold-text'),
            ],
            'downvoteCount' => [
                'type' => 'Int',
                'description' => __('The total number of downvotes', 'heavenhold-text'),
            ],
            'userId' => [
                'type' => 'Int',
                'description' => __('The ID of the user', 'heavenhold-text'),
            ],
            'userVote' => [
                'type' => 'String',
                'description' => __('The current user\'s vote status on the hero: "upvote", "downvote", or "none"', 'heavenhold-text'),
            ],
            'hero' => [
                'type' => 'Hero', 
                'description' => __('The hero details', 'heavenhold-text'),
                'resolve' => function($source, $args, $context, $info) {
                    // Fetch the hero as a WP_Post object
                    $post = get_post($source['heroId']);
                    // Ensure it's wrapped as a WPGraphQL Post object
                    return !empty($post) ? new \WPGraphQL\Model\Post($post) : null;
                }
            ]
        ]
    ]);

    register_graphql_object_type('MetaVoteTotals', [
        'description' => __('Team ID, Vote Count, Downvote Count, and Team Details', 'heavenhold-text'),
        'fields' => [
            'heroId' => [
                'type' => 'Int',
                'description' => __('The hero ID', 'heavenhold-text'),
            ],
            'upvoteCount' => [
                'type' => 'Int',
                'description' => __('The total number of votes', 'heavenhold-text'),
            ],
            'downvoteCount' => [
                'type' => 'Int',
                'description' => __('The total number of downvotes', 'heavenhold-text'),
            ],
        ]
    ]);

    // Add a new field to the RootQuery for getting votes and downvotes by hero
    register_graphql_field('RootQuery', 'metaVotesByCategory', [
        'type' => ['list_of' => 'MetaVoteCount'],
        'description' => __('Get heros and their total vote and downvote counts for a specific category and user', 'heavenhold-text'),
        'args' => [
            'categoryId' => [
                'type' => 'Int',
                'description' => __('The ID of the meta category', 'heavenhold-text'),                
            ],
            'userId' => [
                'type' => 'Int',
                'description' => __('The ID of the user', 'heavenhold-text'),
                'defaultValue' => null,
            ],            
            'ipAddress' => [
                'type' => 'String',
                'description' => __('The IP address of the user', 'heavenhold-text'),
                'defaultValue' => $_SERVER['REMOTE_ADDR'],
            ],
        ],
        'resolve' => function($root, $args, $context, $info) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'meta_votes';
    
            $category_id = $args['categoryId'];
            $user_id = $args['userId'];
            $ip_address = sanitize_text_field($args['ipAddress']);
    
            // Query to get hero_ids and their vote and downvote counts for the specific hero and user
            $query = $wpdb->prepare(
                "SELECT hero_id,
                        SUM(CASE WHEN up_or_down = 1 THEN 1 ELSE 0 END) as upvote_count,
                        SUM(CASE WHEN up_or_down = 0 THEN 1 ELSE 0 END) as downvote_count,
                        MAX(CASE WHEN (user_id = %d OR ip_address = %s) THEN up_or_down ELSE NULL END) as user_vote
                 FROM $table_name
                 WHERE category_id = %d
                 GROUP BY hero_id
                 ORDER BY upvote_count DESC",
                $user_id,
                $ip_address,
                $category_id
            );
    
            // Execute the query and check the results
            $results = $wpdb->get_results($query);
    
            return array_map(function($row) use ($hero_id, $user_id) {
                return [
                    'heroId' => $row->hero_id,
                    'upvoteCount' => intval($row->upvote_count),
                    'downvoteCount' => intval($row->downvote_count),
                    'userId' => $user_id,
                    'userVote' => is_null($row->user_vote) ? 'none' : ($row->user_vote == 1 ? 'upvote' : 'downvote'),
                ];
            }, $results);
        }
    ]);

    // Add a new field to the RootQuery for getting votes and downvotes by hero
    register_graphql_field('RootQuery', 'metaVotesTotals', [
        'type' => ['list_of' => 'MetaVoteTotals'],
        'description' => __('Get heroes and their total vote and downvote counts', 'heavenhold-text'),
        'resolve' => function($root, $args, $context, $info) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'meta_votes';
    
            // Query to get hero_ids and their vote and downvote counts for the specific hero and user
            $query = $wpdb->prepare(
                "SELECT hero_id,
                        SUM(CASE WHEN up_or_down = 1 THEN 1 ELSE 0 END) as upvote_count,
                        SUM(CASE WHEN up_or_down = 0 THEN 1 ELSE 0 END) as downvote_count
                 FROM $table_name                 
                 GROUP BY hero_id
                 ORDER BY upvote_count DESC"
            );
    
            // Execute the query and check the results
            $results = $wpdb->get_results($query);
    
            return array_map(function($row) {
                return [
                    'heroId' => $row->hero_id,
                    'upvoteCount' => intval($row->upvote_count),
                    'downvoteCount' => intval($row->downvote_count),
                ];
            }, $results);
        }
    ]);

    // GraphQL Query to Fetch User's Vote Status
    register_graphql_field('RootQuery', 'userMetaVoteStatus', [
        'type' => 'String',
        'description' => __('Get the current user\'s vote status for a specific category and hero', 'heavenhold-text'),
        'args' => [
            'heroId' => [
                'type' => 'Int',
                'description' => __('The ID of the hero', 'heavenhold-text'),
            ],
            'categoryId' => [
                'type' => 'Int',
                'description' => __('The ID of the category', 'heavenhold-text'),
            ],
            'userId' => [
                'type' => 'Int',
                'description' => __('The ID of the user', 'heavenhold-text'),
                'defaultValue' => null, // Default to null for anonymous users
            ],
            'ipAddress' => [
                'type' => 'String',
                'description' => __('The IP address of the user', 'heavenhold-text'),
            ],
        ],
        'resolve' => function($root, $args, $context, $info) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'meta_votes';

            $user_id = intval($args['userId']);
            $ip_address = sanitize_text_field($args['ipAddress']);
            $category_id = intval($args['categoryId']);
            $hero_id = intval($args['heroId']);

            $vote_status = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT up_or_down FROM $table_name WHERE hero_id = %d AND category_id = %d AND (user_id = %d OR (user_id IS NULL AND ip_address = %s))",
                    $hero_id,
                    $category_id,
                    $user_id,
                    $ip_address
                )
            );

            if ($vote_status === null) {
                return 'none';
            }

            return $vote_status == 1 ? 'upvote' : 'downvote';
        }
    ]);

    // Upvote Mutation with Conditional Logic
    register_graphql_mutation('upvoteHero', [
        'inputFields' => [
            'heroId' => [
                'type' => 'Int',
                'description' => __('The ID of the hero', 'heavenhold-text'),
            ],
            'categoryId' => [
                'type' => 'Int',
                'description' => __('The ID of the category', 'heavenhold-text'),
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
            $table_name = $wpdb->prefix . 'meta_votes';

            // Prepare data for insertion
            $hero_id = intval($input['heroId']);
            $category_id = intval($input['categoryId']);
            $user_id = intval($input['userId']);
            $ip_address = sanitize_text_field($input['ipAddress']);
            $up_or_down = 1;

            // Determine if the user is logged in
            $is_user_logged_in = $user_id !== null;

            // Check existing vote
            if ($is_user_logged_in) {
                // Prioritize user_id
                $existing_vote = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT up_or_down FROM $table_name WHERE hero_id = %d AND category_id = %d AND user_id = %d",
                        $hero_id,
                        $category_id,
                        $user_id
                    )
                );
            } else {
                // Use ip_address for anonymous users
                $existing_vote = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT up_or_down FROM $table_name WHERE hero_id = %d AND category_id = %d AND ip_address = %s",
                        $hero_id,
                        $category_id,
                        $ip_address
                    )
                );
            }

            if ($existing_vote !== null) {
                // Update the existing vote
                $wpdb->update(
                    $table_name,
                    ['up_or_down' => $up_or_down],
                    $is_user_logged_in ?
                    [
                        'hero_id' => $hero_id,
                        'category_id' => $category_id,
                        'user_id' => $user_id
                    ] :
                    [
                        'hero_id' => $hero_id,
                        'category_id' => $category_id,
                        'ip_address' => $ip_address
                    ],
                    ['%d'],
                    $is_user_logged_in ?
                    ['%d', '%d', '%d'] :
                    ['%d', '%d', '%s']
                );
            } else {
                // Insert a new vote
                $wpdb->insert(
                    $table_name,
                    [
                        'hero_id' => $hero_id,
                        'category_id' => $category_id,
                        'user_id' => $user_id,
                        'ip_address' => $ip_address,
                        'up_or_down' => $up_or_down
                    ],
                    ['%d', '%d', '%d', '%s', '%d']
                );
            }

            return ['success' => true, 'currentVote' => 'vote'];
        }
    ]);

    // Downvote Mutation with Conditional Logic
    register_graphql_mutation('downvoteHero', [
        'inputFields' => [
            'heroId' => [
                'type' => 'Int',
                'description' => __('The ID of the hero', 'heavenhold-text'),
            ],
            'categoryId' => [
                'type' => 'Int',
                'description' => __('The ID of the category', 'heavenhold-text'),
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
            $table_name = $wpdb->prefix . 'meta_votes';

            // Prepare data for insertion
            $hero_id = intval($input['heroId']);
            $category_id = intval($input['categoryId']);
            $user_id = intval($input['userId']);
            $ip_address = sanitize_text_field($input['ipAddress']);
            $up_or_down = 0;

            // Determine if the user is logged in
            $is_user_logged_in = $user_id !== null;

            // Check existing vote
            if ($is_user_logged_in) {
                // Prioritize user_id
                $existing_vote = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT up_or_down FROM $table_name WHERE hero_id = %d AND category_id = %d AND user_id = %d",
                        $hero_id,
                        $category_id,
                        $user_id
                    )
                );
            } else {
                // Use ip_address for anonymous users
                $existing_vote = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT up_or_down FROM $table_name WHERE hero_id = %d AND category_id = %d AND ip_address = %s",
                        $hero_id,
                        $category_id,
                        $ip_address
                    )
                );
            }

            if ($existing_vote !== null) {
                // Update the existing vote
                $wpdb->update(
                    $table_name,
                    ['up_or_down' => $up_or_down],
                    $is_user_logged_in ?
                    [
                        'hero_id' => $hero_id,
                        'category_id' => $category_id,
                        'user_id' => $user_id
                    ] :
                    [
                        'hero_id' => $hero_id,
                        'category_id' => $category_id,
                        'ip_address' => $ip_address
                    ],
                    ['%d'],
                    $is_user_logged_in ?
                    ['%d', '%d', '%d'] :
                    ['%d', '%d', '%s']
                );
            } else {
                // Insert a new vote
                $wpdb->insert(
                    $table_name,
                    [
                        'hero_id' => $hero_id,
                        'category_id' => $category_id,
                        'user_id' => $user_id,
                        'ip_address' => $ip_address,
                        'up_or_down' => $up_or_down
                    ],
                    ['%d', '%d', '%d', '%s', '%d']
                );
            }

            return ['success' => true, 'currentVote' => 'downvote'];
        }
    ]);


    // Existing TeamVote type definition...
    register_graphql_object_type('MetaVote', [
        'description' => __('Votes per hero', 'heavenhold-text'),
        'interfaces' => ['Node', 'DatabaseIdentifier'],
        'fields' => [
            'heroDatabaseId' => [
                'type' => 'Int',
                'description' => __('The hero id', 'heavenhold-text'),
                'resolve' => function ($source) {
                    return $source->hero_id;
                }
            ],
            'categoryId' => [
                'type' => 'Int',
                'description' => __('The category id', 'heavenhold-text'),
                'resolve' => function ($source) {
                    return $source->category_id;
                }
            ],
            'userDatabaseId' => [
                'type' => 'Int',
                'description' => __('The user account associated with the vote', 'heavenhold-text'),
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
        'toType' => 'MetaVote',
        'fromFieldName' => 'metaVotes',
        'resolve' => function ($root, $args, $context, $info) {
            $resolver = new TeamVotesConnectionResolver($root, $args, $context, $info);
            return $resolver->get_connection();
        }
    ]);

    register_graphql_connection([
        'fromType' => 'MetaVote',
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
        'toType' => 'MetaVote',
        'fromFieldName' => 'metaVotes',
        'resolve' => function ($root, $args, $context, $info) {
            $resolver = new TeamVotesConnectionResolver($root, $args, $context, $info);
            $resolver->set_query_arg('user_id', $root->databaseId);
            return $resolver->get_connection();
        }
    ]);

    // Register connection from TeamVote to Team using heroId
    register_graphql_connection([
        'fromType' => 'MetaVote',
        'toType' => 'Hero',
        'fromFieldName' => 'hero',
        'oneToOne' => true, // Define as a one-to-one connection
        'resolve' => function($root, $args, $context, $info) {
            $resolver = new \WPGraphQL\Data\Connection\PostObjectConnectionResolver($root, $args, $context, $info);
            $resolver->set_query_arg('include', $root->hero_id);
            return $resolver->one_to_one()->get_connection();
        }
    ]);
}

add_action('graphql_init', function() {


    class MetaVoteLoader extends \WPGraphQL\Data\Loader\AbstractDataLoader {


        public function loadKeys(array $keys): array {
            if (empty($keys)) {
                return [];
            }

            global $wpdb;

            // Prepare a SQL query to select rows that match the given IDs
            $table_name = $wpdb->prefix . 'meta_votes';
            $ids = implode(', ', array_map('intval', $keys)); // Sanitize input
            $query = "SELECT * FROM $table_name WHERE vote_id IN ($ids) ORDER BY vote_id ASC";
            $results = $wpdb->get_results($query);

            if (empty($results)) {
                return [];
            }

            // Convert the array of votes to an associative array keyed by their IDs
            $metaVotesById = [];
            foreach ($results as $result) {
                // Ensure the vote is returned with the TeamVote __typename
                $result->__typename = 'MetaVote';
                $metaVotesById[$result->vote_id] = $result;
            }

            // Create an ordered array based on the ordered IDs
            $orderedTeamVotes = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $metaVotesById)) {
                    $orderedTeamVotes[$key] = $metaVotesById[$key];
                }
            }

            return $orderedTeamVotes;
        }
    }

    // Add the votes loader to be used under the hood by WPGraphQL when loading nodes
    add_filter('graphql_data_loaders', function($loaders, $context) {
        $loaders['metaVote'] = new MetaVoteLoader($context);
        return $loaders;
    }, 10, 2);

    // Filter so nodes that have a __typename will return that typename
    add_filter('graphql_resolve_node_type', function($type, $node) {
        return $node->__typename ?? $type;
    }, 10, 2);
});

add_action('graphql_init', function() {

    class MetaVotesConnectionResolver extends \WPGraphQL\Data\Connection\AbstractConnectionResolver {

        // Tell WPGraphQL which Loader to use. We define the `metaVote` loader that we registered already.
        public function get_loader_name(): string {
            return 'metaVote';
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
        // You could use an ORM to access data or whatever else you vote here.
        public function get_query(): array {
            global $wpdb;

            // Simplified query to fetch IDs
            $ids_array = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT vote_id FROM ' . $wpdb->prefix . 'meta_votes LIMIT 10'
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