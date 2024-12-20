<?php
function create_team_votes_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'team_votes';

    $charset_collate = $wpdb->get_charset_collate();

    // Updated SQL with up_or_down column
    $sql = "CREATE TABLE $table_name (
        vote_id BIGINT NOT NULL AUTO_INCREMENT,
        team_id BIGINT NOT NULL,
        user_id BIGINT,
        vote_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(15) NOT NULL,
        up_or_down TINYINT NOT NULL,
        PRIMARY KEY (vote_id),
        UNIQUE KEY unique_vote_user (team_id, user_id),
        UNIQUE KEY unique_vote_ip (team_id, ip_address)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Hook into WPGraphQL as it builds the Schema
add_action('graphql_register_types', 'team_votes_table_register_types');

function team_votes_table_register_types() {
    // Register a new type for the vote and downvote count
    register_graphql_object_type('TeamVoteCount', [
        'description' => __('Team ID, Vote Count, Downvote Count, and Team Details', 'heavenhold-text'),
        'fields' => [
            'teamId' => [
                'type' => 'Int',
                'description' => __('The team ID', 'heavenhold-text'),
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
                'description' => __('The current user\'s vote status on the team: "upvote", "downvote", or "none"', 'heavenhold-text'),
            ],
            'team' => [
                'type' => 'Team',  // Assuming you have an Team GraphQL type
                'description' => __('The team details', 'heavenhold-text'),
                'resolve' => function($source, $args, $context, $info) {
                    // Fetch the team as a WP_Post object
                    $post = get_post($source['teamId']);
                    // Ensure it's wrapped as a WPGraphQL Post object
                    return !empty($post) ? new \WPGraphQL\Model\Post($post) : null;
                }
            ]
        ]
    ]);

    register_graphql_object_type('TeamVoteInfo', [
        'description' => __('Team ID, Vote Count, Downvote Count', 'heavenhold-text'),
        'fields' => [
            'teamId' => [
                'type' => 'Int',
                'description' => __('The team ID', 'heavenhold-text'),
            ],
            'upvoteCount' => [
                'type' => 'Int',
                'description' => __('The total number of votes', 'heavenhold-text'),
            ],
            'downvoteCount' => [
                'type' => 'Int',
                'description' => __('The total number of downvotes', 'heavenhold-text'),
            ]
        ]
    ]);

    register_graphql_object_type('TeamUserVote', [
        'description' => __('Team ID, Vote Count, Downvote Count, and Team Details', 'heavenhold-text'),
        'fields' => [
            'teamId' => [
                'type' => 'Int',
                'description' => __('The team ID', 'heavenhold-text'),
            ],
            'userVote' => [
                'type' => 'String',
                'description' => __('The current user\'s vote status on the team: "upvote", "downvote", or "none"', 'heavenhold-text'),
            ],
        ]
    ]);

    register_graphql_field('RootQuery', 'getUserTeamVoteStatus', [
        'type' => ['list_of' => 'TeamUserVote'],
        'description' => __('Get the vote status of a user for a particular team', 'heavenhold-text'),
        'args' => [
            'userId' => [
                'type' => 'Int',
                'description' => __('The ID of the user', 'heavenhold-text'),
                'defaultValue' => null, // Default to null for anonymous users
            ],
            'ipAddress' => [
                'type' => 'String',
                'description' => __('The IP address of the user', 'heavenhold-text'),
                'defaultValue' => $_SERVER['REMOTE_ADDR'],
            ],
        ],
        'resolve' => function($root, $args, $context, $info) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'team_votes';
            $user_id = $args['userId'];
            $ip_address = sanitize_text_field($args['ipAddress']);

            $query = $wpdb->prepare(
                "SELECT team_id, up_or_down AS user_vote FROM $table_name WHERE (user_id = %d OR (ip_address = %s AND ip_address != '' AND ip_address IS NOT NULL))",
                $user_id,
                $ip_address,
            );

            // Execute the query and check the results
            $results = $wpdb->get_results($query);

            return array_map(function($row) {
                return [
                    'teamId' => $row->team_id,
                    'userVote' => $row->user_vote == 1 ? 'upvote' : 'downvote',
                ];
            }, $results);
        }
    ]);

    // Add a new field to the RootQuery for getting votes and downvotes by hero
    register_graphql_field('RootQuery', 'teamsVotesWithUserVote', [
        'type' => ['list_of' => 'TeamVoteCount'],
        'description' => __('Get teams and their total vote and downvote counts for a specific user', 'heavenhold-text'),
        'args' => [
            'userId' => [
                'type' => 'Int',
                'description' => __('The ID of the user', 'heavenhold-text'),
                'defaultValue' => null, // Default to null for anonymous users
            ],
            'ipAddress' => [
                'type' => 'String',
                'description' => __('The IP address of the user', 'heavenhold-text'),
                'defaultValue' => $_SERVER['REMOTE_ADDR'],
            ],
        ],
        'resolve' => function($root, $args, $context, $info) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'team_votes';
            $user_id = $args['userId'];
            $ip_address = sanitize_text_field($args['ipAddress']);
    
            // Query to get team_ids and their vote and downvote counts for the specific hero and user
            $query = $wpdb->prepare(
                "SELECT team_id,
                        SUM(CASE WHEN up_or_down = 1 THEN 1 ELSE 0 END) as upvote_count,
                        SUM(CASE WHEN up_or_down = 0 THEN 1 ELSE 0 END) as downvote_count,
                        MAX(CASE WHEN (user_id = %d OR ip_address = %s) THEN up_or_down ELSE NULL END) as user_vote
                 FROM $table_name
                 GROUP BY team_id
                 ORDER BY upvote_count DESC",
                $user_id,
                $ip_address,
            );
    
            // Execute the query and check the results
            $results = $wpdb->get_results($query);
    
            return array_map(function($row) use ($user_id) {
                return [
                    'teamId' => $row->team_id,
                    'upvoteCount' => intval($row->upvote_count),
                    'downvoteCount' => intval($row->downvote_count),
                    'userId' => $user_id,
                    'userVote' => is_null($row->user_vote) ? 'none' : ($row->user_vote == 1 ? 'upvote' : 'downvote'),
                ];
            }, $results);
        }
    ]);

    // Add a new field to the RootQuery for getting votes and downvotes by hero
    register_graphql_field('RootQuery', 'getTeamVotes', [
        'type' => ['list_of' => 'TeamVoteInfo'],
        'description' => __('Get teams and their total vote and downvote counts for a specific hero and user', 'heavenhold-text'),
        'resolve' => function($root, $args, $context, $info) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'team_votes';
    
            // Query to get team_ids and their vote and downvote counts for the specific hero and user
            $query = $wpdb->prepare(
                "SELECT team_id,
                        SUM(CASE WHEN up_or_down = 1 THEN 1 ELSE 0 END) as upvote_count,
                        SUM(CASE WHEN up_or_down = 0 THEN 1 ELSE 0 END) as downvote_count
                 FROM $table_name
                 GROUP BY team_id
                 ORDER BY upvote_count DESC",
            );
    
            // Execute the query and check the results
            $results = $wpdb->get_results($query);
    
            return array_map(function($row) {
                return [
                    'teamId' => $row->team_id,
                    'upvoteCount' => intval($row->upvote_count),
                    'downvoteCount' => intval($row->downvote_count),
                ];
            }, $results);
        }
    ]);

    // GraphQL Query to Fetch User's Vote Status
    register_graphql_field('RootQuery', 'userVoteStatus', [
        'type' => 'String',
        'description' => __('Get the current user\'s vote status for a specific hero and team', 'heavenhold-text'),
        'args' => [
            'teamId' => [
                'type' => 'Int',
                'description' => __('The ID of the team', 'heavenhold-text'),
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
            $table_name = $wpdb->prefix . 'team_votes';

            $user_id = intval($args['userId']);
            $ip_address = sanitize_text_field($args['ipAddress']);
            $team_id = intval($args['teamId']);

            $vote_status = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT up_or_down FROM $table_name WHERE team_id = %d AND (user_id = %d OR (user_id IS NULL AND ip_address = %s))",
                    $team_id,
                    $user_id,
                    $ip_address
                )
            );

            if ($vote_status === null) {
                return 'none';
            }

            return $vote_status == 1 ? 'vote' : 'downvote';
        }
    ]);

    // Upvote Mutation with Conditional Logic
    register_graphql_mutation('upvoteTeam', [
        'inputFields' => [
            'teamId' => [
                'type' => 'Int',
                'description' => __('The ID of the team', 'heavenhold-text'),
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
            'teamId' => [
                'type' => 'Int',
                'description' => __('The ID of the team', 'heavenhold-text'),
            ],
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
            $table_name = $wpdb->prefix . 'team_votes';
            $team_id = intval($input['teamId']);
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
                        "SELECT up_or_down FROM $table_name WHERE team_id = %d AND user_id = %d",
                        $team_id,
                        $user_id
                    )
                );
            } else {
                // Use ip_address for anonymous users
                $existing_vote = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT up_or_down FROM $table_name WHERE team_id = %d AND ip_address = %s",
                        $team_id,
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
                        'team_id' => $team_id,
                        'user_id' => $user_id
                    ] :
                    [
                        'team_id' => $team_id,
                        'ip_address' => $ip_address
                    ],
                    ['%d'],
                    $is_user_logged_in ?
                    ['%d', '%d'] :
                    ['%d', '%s']
                );
            } else {
                // Insert a new vote
                $wpdb->insert(
                    $table_name,
                    [
                        'team_id' => $team_id,
                        'user_id' => $user_id,
                        'ip_address' => $ip_address,
                        'up_or_down' => $up_or_down
                    ],
                    ['%d', '%d', '%s', '%d']
                );
            }

            return ['teamId' => $team_id, 'success' => true, 'currentVote' => 'vote'];
        }
    ]);

    // Downvote Mutation with Conditional Logic
    register_graphql_mutation('downvoteTeam', [
        'inputFields' => [
            'teamId' => [
                'type' => 'Int',
                'description' => __('The ID of the team', 'heavenhold-text'),
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
            'teamId' => [
                'type' => 'Int',
                'description' => __('The ID of the team', 'heavenhold-text'),
            ],
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
            $table_name = $wpdb->prefix . 'team_votes';
            $team_id = intval($input['teamId']);
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
                        "SELECT up_or_down FROM $table_name WHERE team_id = %d AND user_id = %d",
                        $team_id,
                        $user_id
                    )
                );
            } else {
                // Use ip_address for anonymous users
                $existing_vote = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT up_or_down FROM $table_name WHERE team_id = %d AND ip_address = %s",
                        $team_id,
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
                        'team_id' => $team_id,
                        'user_id' => $user_id
                    ] :
                    [
                        'team_id' => $team_id,
                        'ip_address' => $ip_address
                    ],
                    ['%d'],
                    $is_user_logged_in ?
                    ['%d', '%d'] :
                    ['%d', '%s']
                );
            } else {
                // Insert a new vote
                $wpdb->insert(
                    $table_name,
                    [
                        'team_id' => $team_id,
                        'user_id' => $user_id,
                        'ip_address' => $ip_address,
                        'up_or_down' => $up_or_down
                    ],
                    ['%d', '%d', '%s', '%d']
                );
            }

            return ['teamId' => $team_id, 'success' => true, 'currentVote' => 'downvote'];
        }
    ]);


    // Existing TeamVote type definition...
    register_graphql_object_type('TeamVote', [
        'description' => __('Votes per hero team team option', 'heavenhold-text'),
        'interfaces' => ['Node', 'DatabaseIdentifier'],
        'fields' => [
            'teamDatabaseId' => [
                'type' => 'Int',
                'description' => __('The team id', 'heavenhold-text'),
                'resolve' => function ($source) {
                    return $source->team_id;
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
        'toType' => 'TeamVote',
        'fromFieldName' => 'teamVotes',
        'resolve' => function ($root, $args, $context, $info) {
            $resolver = new TeamVotesConnectionResolver($root, $args, $context, $info);
            return $resolver->get_connection();
        }
    ]);

    register_graphql_connection([
        'fromType' => 'TeamVote',
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
        'toType' => 'TeamVote',
        'fromFieldName' => 'teamVotes',
        'resolve' => function ($root, $args, $context, $info) {
            $resolver = new TeamVotesConnectionResolver($root, $args, $context, $info);
            $resolver->set_query_arg('user_id', $root->databaseId);
            return $resolver->get_connection();
        }
    ]);

    // Register connection from TeamVote to Team using teamId
    register_graphql_connection([
        'fromType' => 'TeamVote',
        'toType' => 'Team',
        'fromFieldName' => 'team',
        'oneToOne' => true, // Define as a one-to-one connection
        'resolve' => function($root, $args, $context, $info) {
            $resolver = new \WPGraphQL\Data\Connection\PostObjectConnectionResolver($root, $args, $context, $info);
            $resolver->set_query_arg('include', $root->team_id);
            return $resolver->one_to_one()->get_connection();
        }
    ]);
}

add_action('graphql_init', function() {

    /**
     * Class TeamVoteLoader
     *
     * This is a custom loader that extends the WPGraphQL Abstract Data Loader.
     */
    class TeamVoteLoader extends \WPGraphQL\Data\Loader\AbstractDataLoader {

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
            $table_name = $wpdb->prefix . 'team_votes';
            $ids = implode(', ', array_map('intval', $keys)); // Sanitize input
            $query = "SELECT * FROM $table_name WHERE vote_id IN ($ids) ORDER BY vote_id ASC";
            $results = $wpdb->get_results($query);

            if (empty($results)) {
                return [];
            }

            // Convert the array of votes to an associative array keyed by their IDs
            $teamVotesById = [];
            foreach ($results as $result) {
                // Ensure the vote is returned with the TeamVote __typename
                $result->__typename = 'TeamVote';
                $teamVotesById[$result->vote_id] = $result;
            }

            // Create an ordered array based on the ordered IDs
            $orderedTeamVotes = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $teamVotesById)) {
                    $orderedTeamVotes[$key] = $teamVotesById[$key];
                }
            }

            return $orderedTeamVotes;
        }
    }

    // Add the votes loader to be used under the hood by WPGraphQL when loading nodes
    add_filter('graphql_data_loaders', function($loaders, $context) {
        $loaders['teamVote'] = new TeamVoteLoader($context);
        return $loaders;
    }, 10, 2);

    // Filter so nodes that have a __typename will return that typename
    add_filter('graphql_resolve_node_type', function($type, $node) {
        return $node->__typename ?? $type;
    }, 10, 2);
});

add_action('graphql_init', function() {

    class TeamVotesConnectionResolver extends \WPGraphQL\Data\Connection\AbstractConnectionResolver {

        // Tell WPGraphQL which Loader to use. We define the `teamVote` loader that we registered already.
        public function get_loader_name(): string {
            return 'teamVote';
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
                    'SELECT vote_id FROM ' . $wpdb->prefix . 'team_votes LIMIT 10'
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