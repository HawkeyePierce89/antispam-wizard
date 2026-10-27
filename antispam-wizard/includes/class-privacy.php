<?php
/**
 * WordPress Privacy API integration: personal data exporter, eraser and
 * suggested privacy policy text.
 *
 * @package AntiSpamWizard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Integrates the plugin with the WordPress "Export/Erase Personal Data" tools.
 *
 * Plugin data is keyed by comment_id. Given a data subject's email, WordPress
 * core locates the matching comments; this class uses their comment_id to
 * export or erase the corresponding spam-log entries, the `_antispamwizard_reason`
 * comment meta and the training samples added from those comments.
 *
 * Data that outlives its comment carries a second key, the salted SHA-256 email
 * hash: form spam-log entries (which never had a comment) and training samples
 * (whose source comment is routinely hard-deleted by emptying the spam queue,
 * while the stored text is kept for the classifier). Both are emitted by hash on
 * page 1 only, so paginated comment pages cannot duplicate them.
 */
class ANTISPAMWIZARD_Privacy {

    /**
     * Unique key for the exporter/eraser registration and data groups.
     */
    const PRIVACY_KEY = 'antispam-wizard';

    /**
     * Number of comments processed per pagination page.
     */
    const COMMENTS_PER_PAGE = 50;

    /**
     * Register the Privacy API hooks.
     */
    public function register_hooks(): void {
        add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
        // Priority 5 puts this eraser ahead of core's 'wordpress-comments'
        // eraser (registered at 10 in default-filters.php). WordPress runs
        // erasers strictly in array order, and core's blanks comment_author_email
        // on every comment it anonymizes -- so if it ran first, the subject
        // lookup below would find no comments and this eraser would silently
        // remove nothing while reporting success. Only comments the site later
        // moved out of the spam queue are affected (core skips 'spam'/'trash'),
        // but those are exactly the false positives a person is most likely to
        // file an erasure request about.
        add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ), 5 );
        add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
    }

    /**
     * Register the personal data exporter.
     *
     * @param array $exporters Registered exporters.
     * @return array
     */
    public function register_exporter( array $exporters ): array {
        $exporters[ self::PRIVACY_KEY ] = array(
            'exporter_friendly_name' => __( 'AntiSpam Wizard', 'antispam-wizard' ),
            'callback'               => array( $this, 'export' ),
        );
        return $exporters;
    }

    /**
     * Register the personal data eraser.
     *
     * @param array $erasers Registered erasers.
     * @return array
     */
    public function register_eraser( array $erasers ): array {
        $erasers[ self::PRIVACY_KEY ] = array(
            'eraser_friendly_name' => __( 'AntiSpam Wizard', 'antispam-wizard' ),
            'callback'             => array( $this, 'erase' ),
        );
        return $erasers;
    }

    /**
     * Collect the comment IDs for a data subject on a given page.
     *
     * @param string $email Data subject email address.
     * @param int    $page  1-based pagination page.
     * @return int[] Comment IDs.
     */
    private function get_comment_ids( string $email, int $page ): array {
        $ids = get_comments(
            array(
                'author_email' => $email,
                'number'       => self::COMMENTS_PER_PAGE,
                'paged'        => max( 1, $page ),
                'fields'       => 'ids',
                // Stable pagination: the default comment_date_gmt ordering has no
                // tie-breaker, and spam arrives in bursts with identical dates.
                'orderby'      => 'comment_ID',
                'order'        => 'ASC',
                // The plugin flags spam by returning 'spam' from pre_comment_approved,
                // so its data is attached to comments stored with comment_approved = 'spam'
                // (and possibly 'trash'). WP_Comment_Query defaults to 'all', which matches
                // only held/approved comments and would exclude the very comments this
                // exporter/eraser targets. 'any' matches comments of every status.
                'status'       => 'any',
            )
        );
        if ( ! is_array( $ids ) ) {
            return array();
        }
        return array_map( 'intval', $ids );
    }

    /**
     * Which of these samples' comment IDs the comment-keyed export pass will
     * reach on a page after the current one.
     *
     * Runs the same subject lookup as get_comment_ids(), so "a comment of this
     * subject" is decided identically in both places -- a row this reports as
     * deferred is a row that page really does emit. A comment_id of 0, one whose
     * comment was hard-deleted, and one belonging to somebody else are all
     * unreachable that way and must be exported by hash instead.
     *
     * @param string                   $email    Data subject email address.
     * @param array<int, array>        $samples  Sample rows returned by the hash lookup.
     * @param array<int, int>          $page_ids Comment IDs on the current page.
     * @return array<int, true> Comment IDs claimed by a later page, as keys.
     */
    private function comment_ids_on_later_pages( string $email, array $samples, array $page_ids ): array {
        $on_this_page = array_flip( $page_ids );
        $candidates   = array();
        foreach ( $samples as $sample ) {
            $comment_id = (int) ( $sample['comment_id'] ?? 0 );
            if ( $comment_id > 0 && ! isset( $on_this_page[ $comment_id ] ) ) {
                $candidates[ $comment_id ] = $comment_id;
            }
        }
        if ( empty( $candidates ) ) {
            return array();
        }

        $found = get_comments(
            array(
                'author_email' => $email,
                'comment__in'  => array_values( $candidates ),
                'fields'       => 'ids',
                // Same status semantics as get_comment_ids(): the plugin's data
                // hangs off comments stored as 'spam'/'trash', which the default
                // 'all' would exclude.
                'status'       => 'any',
            )
        );
        if ( ! is_array( $found ) ) {
            return array();
        }

        $deferred = array();
        foreach ( $found as $comment_id ) {
            $deferred[ (int) $comment_id ] = true;
        }
        return $deferred;
    }

    /**
     * Export the plugin's personal data for a data subject.
     *
     * @param string $email Data subject email address.
     * @param int    $page  1-based pagination page.
     * @return array Exporter response: ['data' => [...], 'done' => bool].
     */
    public function export( string $email, int $page = 1 ): array {
        // Normalise once, up front: get_comment_ids() clamps with max( 1, $page )
        // while the hash-keyed lookups below run on page 1 only. Without this a
        // page of 0 would export page-1 comments but silently skip every
        // hash-keyed row, and still report the request done.
        $page        = max( 1, $page );
        $comment_ids = $this->get_comment_ids( $email, $page );
        $export_data = array();

        if ( ! empty( $comment_ids ) ) {
            $entries = ANTISPAMWIZARD_Spam_Log::get_entries_by_comment_ids( $comment_ids );

            // Index log entries by comment_id for lookup.
            $by_comment = array();
            foreach ( $entries as $entry ) {
                $cid = (int) $entry['comment_id'];
                if ( ! isset( $by_comment[ $cid ] ) ) {
                    $by_comment[ $cid ] = array();
                }
                $by_comment[ $cid ][] = $entry;
            }

            foreach ( $comment_ids as $comment_id ) {
                $reason         = (string) get_comment_meta( $comment_id, '_antispamwizard_reason', true );
                $comment_groups = isset( $by_comment[ $comment_id ] ) ? $by_comment[ $comment_id ] : array();

                if ( empty( $comment_groups ) && '' === $reason ) {
                    continue;
                }

                if ( empty( $comment_groups ) ) {
                    // Reason meta exists but no log entry remains: still export it.
                    $export_data[] = $this->build_data_group( $comment_id, array(), $reason );
                    continue;
                }

                foreach ( $comment_groups as $entry ) {
                    $export_data[] = $this->build_data_group( $comment_id, $entry, $reason );
                }
            }
        }

        $hash = ANTISPAMWIZARD_Detector::hash_email( $email );

        // Training samples. They carry both subject keys: comment_id, which
        // paginates with the comments, and email_hash, which still resolves after
        // the source comment was hard-deleted (emptying the spam queue) and is
        // therefore emitted once, on page 1, like the form entries below.
        //
        // A sample reachable by both keys must be exported exactly once, and the
        // two keys paginate differently: the hash query runs on page 1 while the
        // row's comment may fall on page 3. Indexing rows by ID collapses a
        // duplicate within a page but never across pages, and WordPress merges
        // export groups sharing an item_id, so a cross-page duplicate surfaces as
        // the full comment text repeated inside one item.
        //
        // The split is therefore decided per row, from facts that do not depend
        // on what another page's queries returned: a hash row is withheld on
        // page 1 only when the comment-keyed pass is going to claim it on a
        // *later* page, i.e. its comment_id still resolves to a comment of this
        // subject and is not among this page's IDs. Deciding it instead from the
        // row IDs some other page's hash query returned is what cannot work: a
        // hash query that failed on page 1 and succeeded on page 2 would have
        // page 2 skip a row page 1 never emitted, dropping the full comment text
        // from a report still marked done.
        //
        // Every remaining failure mode costs a duplicate, never an omission.
        // find_by_email_hash() reports a failed read as an empty array, so
        // nothing is withheld that the comment-keyed pass will not emit; and a
        // reachability lookup that comes back empty makes page 1 emit the row as
        // well as the page its comment falls on.
        $repository = ANTISPAMWIZARD::get_instance()->get_repository();
        if ( $repository instanceof ANTISPAMWIZARD_Sample_Repository ) {
            $samples = array();

            if ( ! empty( $comment_ids ) ) {
                foreach ( $repository->find_by_comment_ids( $comment_ids ) as $sample ) {
                    $samples[ (int) ( $sample['id'] ?? 0 ) ] = $sample;
                }
            }

            if ( 1 === $page && '' !== $hash ) {
                $hash_rows = $repository->find_by_email_hash( $hash );
                $deferred  = $this->comment_ids_on_later_pages( $email, $hash_rows, $comment_ids );
                foreach ( $hash_rows as $sample ) {
                    if ( isset( $deferred[ (int) ( $sample['comment_id'] ?? 0 ) ] ) ) {
                        continue;
                    }
                    $samples[ (int) ( $sample['id'] ?? 0 ) ] = $sample;
                }
            }

            ksort( $samples );
            foreach ( $samples as $sample ) {
                $export_data[] = $this->build_sample_data_group( $sample );
            }
        }

        // Form entries are keyed by email hash, not comment_id. Emit them only
        // on page 1 so they are not duplicated across paginated comment pages.
        // The done calculation stays driven by the comment count.
        if ( 1 === $page && '' !== $hash ) {
            $form_entries = ANTISPAMWIZARD_Spam_Log::get_entries_by_email_hash( $hash );
            foreach ( $form_entries as $entry ) {
                $export_data[] = $this->build_form_data_group( $entry );
            }
        }

        return array(
            'data' => $export_data,
            'done' => count( $comment_ids ) < self::COMMENTS_PER_PAGE,
        );
    }

    /**
     * Build a single exporter data group for a comment / log entry pair.
     *
     * @param int    $comment_id Comment ID.
     * @param array  $entry      Spam-log entry (may be empty).
     * @param string $reason     The `_antispamwizard_reason` meta value.
     * @return array
     */
    private function build_data_group( int $comment_id, array $entry, string $reason ): array {
        $data = array();

        if ( ! empty( $entry['date'] ) ) {
            $data[] = array(
                'name'  => __( 'Date flagged', 'antispam-wizard' ),
                'value' => $entry['date'],
            );
        }
        if ( ! empty( $entry['text_excerpt'] ) ) {
            $data[] = array(
                'name'  => __( 'Comment fragment', 'antispam-wizard' ),
                'value' => $entry['text_excerpt'],
            );
        }
        if ( ! empty( $entry['ip_hash'] ) ) {
            $data[] = array(
                'name'  => __( 'IP address (SHA-256 hash)', 'antispam-wizard' ),
                'value' => $entry['ip_hash'],
            );
        }
        if ( ! empty( $entry['checks_triggered'] ) && is_array( $entry['checks_triggered'] ) ) {
            $data[] = array(
                'name'  => __( 'Triggered checks', 'antispam-wizard' ),
                'value' => implode( ', ', $entry['checks_triggered'] ),
            );
        }
        if ( '' !== $reason ) {
            $data[] = array(
                'name'  => __( 'Reason flagged', 'antispam-wizard' ),
                'value' => $reason,
            );
        }

        $suffix = ! empty( $entry['entry_id'] ) ? '-' . $entry['entry_id'] : '';
        return array(
            'group_id'    => self::PRIVACY_KEY,
            'group_label' => __( 'AntiSpam Wizard', 'antispam-wizard' ),
            'item_id'     => self::PRIVACY_KEY . '-comment-' . $comment_id . $suffix,
            'data'        => $data,
        );
    }

    /**
     * Build a single exporter data group for a form spam-log entry.
     *
     * @param array $entry Spam-log entry with source 'form'.
     * @return array
     */
    private function build_form_data_group( array $entry ): array {
        $data = array();

        if ( ! empty( $entry['date'] ) ) {
            $data[] = array(
                'name'  => __( 'Date flagged', 'antispam-wizard' ),
                'value' => $entry['date'],
            );
        }
        if ( ! empty( $entry['text_excerpt'] ) ) {
            $data[] = array(
                'name'  => __( 'Submission fragment', 'antispam-wizard' ),
                'value' => $entry['text_excerpt'],
            );
        }
        if ( ! empty( $entry['ip_hash'] ) ) {
            $data[] = array(
                'name'  => __( 'IP address (SHA-256 hash)', 'antispam-wizard' ),
                'value' => $entry['ip_hash'],
            );
        }
        if ( ! empty( $entry['checks_triggered'] ) && is_array( $entry['checks_triggered'] ) ) {
            $data[] = array(
                'name'  => __( 'Triggered checks', 'antispam-wizard' ),
                'value' => implode( ', ', $entry['checks_triggered'] ),
            );
        }

        return array(
            'group_id'    => self::PRIVACY_KEY,
            'group_label' => __( 'AntiSpam Wizard', 'antispam-wizard' ),
            'item_id'     => self::PRIVACY_KEY . '-form-' . $entry['entry_id'],
            'data'        => $data,
        );
    }

    /**
     * Build a single exporter data group for a training sample.
     *
     * Unlike the spam-log entry, a training sample stores the comment text in
     * full, so the export carries it verbatim.
     *
     * @param array $sample Sample row keyed by column.
     * @return array
     */
    private function build_sample_data_group( array $sample ): array {
        $data = array();

        if ( ! empty( $sample['created_at'] ) ) {
            $data[] = array(
                'name'  => __( 'Date added to training data', 'antispam-wizard' ),
                'value' => $sample['created_at'],
            );
        }

        $category = isset( $sample['category'] ) ? (string) $sample['category'] : '';
        if ( 'spam' === $category ) {
            $data[] = array(
                'name'  => __( 'Training category', 'antispam-wizard' ),
                'value' => __( 'Spam', 'antispam-wizard' ),
            );
        } elseif ( 'ham' === $category ) {
            $data[] = array(
                'name'  => __( 'Training category', 'antispam-wizard' ),
                'value' => __( 'Ham', 'antispam-wizard' ),
            );
        }

        $content = isset( $sample['content'] ) ? (string) $sample['content'] : '';
        if ( '' !== $content ) {
            $data[] = array(
                'name'  => __( 'Training sample text', 'antispam-wizard' ),
                'value' => $content,
            );
        }

        return array(
            'group_id'    => self::PRIVACY_KEY,
            'group_label' => __( 'AntiSpam Wizard', 'antispam-wizard' ),
            'item_id'     => self::PRIVACY_KEY . '-sample-' . (int) ( $sample['id'] ?? 0 ),
            'data'        => $data,
        );
    }

    /**
     * Erase the plugin's personal data for a data subject.
     *
     * @param string $email Data subject email address.
     * @param int    $page  1-based pagination page.
     * @return array Eraser response.
     */
    public function erase( string $email, int $page = 1 ): array {
        // Normalise once, up front, for the same reason as in export(): a page
        // of 0 would otherwise erase page-1 comment data while skipping every
        // hash-keyed row, and still report the request done.
        $page           = max( 1, $page );
        $comment_ids    = $this->get_comment_ids( $email, $page );
        $items_removed  = false;
        $items_retained = false;

        if ( ! empty( $comment_ids ) ) {
            $deleted = ANTISPAMWIZARD_Spam_Log::delete_entries_by_comment_ids( $comment_ids );
            if ( $deleted > 0 ) {
                $items_removed = true;
            }
            foreach ( $comment_ids as $comment_id ) {
                if ( '' !== (string) get_comment_meta( $comment_id, '_antispamwizard_reason', true ) ) {
                    delete_comment_meta( $comment_id, '_antispamwizard_reason' );
                    $items_removed = true;
                }
            }
        }

        $hash = ANTISPAMWIZARD_Detector::hash_email( $email );

        // Training samples. Erased by both subject keys: this page's comment IDs,
        // and -- on page 1 only, like the form entries below -- the email hash,
        // which still reaches samples whose source comment was hard-deleted.
        // Deleting rows bumps the sample revision, so the cached classifier is
        // rebuilt without the erased text.
        //
        // A false return means the DELETE failed, not that nothing matched: the
        // rows still hold the full comment text, so the response must say so.
        // Reporting completion instead would end the erasure request with the
        // data still stored.
        $repository      = ANTISPAMWIZARD::get_instance()->get_repository();
        $samples_removed = false;
        if ( $repository instanceof ANTISPAMWIZARD_Sample_Repository ) {
            $erase_by_comment = ! empty( $comment_ids );
            $erase_by_hash    = ( 1 === $page && '' !== $hash );

            if ( $erase_by_comment || $erase_by_hash ) {
                $deletions = array();
                if ( $erase_by_comment ) {
                    $deletions[] = $repository->delete_by_comment_ids( $comment_ids );
                }
                if ( $erase_by_hash ) {
                    $deletions[] = $repository->delete_by_email_hash( $hash );
                }

                foreach ( $deletions as $deleted_samples ) {
                    if ( false === $deleted_samples ) {
                        $items_retained = true;
                    } elseif ( $deleted_samples > 0 ) {
                        $items_removed   = true;
                        $samples_removed = true;
                    }
                }
            }
        }

        // A cached model unit holds the erased comment's text as a token list,
        // so an erasure that reported success while the cache still served it
        // would leave a readable copy in wp_options for the rest of that unit's
        // day. Every unit is dropped rather than only the affected script's,
        // because the combined unit holds every script's samples and the erased
        // rows' scripts are not known here. The keys name the units and are
        // exhaustive, so this reaches an older generation as well -- a unit is
        // fingerprinted inside its value, not in its key, precisely so that a
        // rebuild overwrites the unit it replaces instead of orphaning it out of
        // reach. The store additionally sweeps its transient prefix out of
        // wp_options, which is the only way to reach a unit row written under a
        // name this version cannot compute: the monolithic transient earlier
        // releases cached, and rows stranded by an object cache installed after
        // the fact.
        //
        // Page 1 purges whether or not this request deleted a sample, and that
        // is what makes the "please retry this erasure request" message below
        // true: the retry it asks for deletes no sample -- the rows went the
        // first time -- so a purge gated on this request's own deletions would
        // simply be skipped and the second request would close clean over the
        // unit the first one could not drop. Gating it on a durable record of
        // the failed purge instead would put that promise behind the very
        // option store whose refusal is the failure being recorded. The cost is
        // one model-cache flush per erasure request -- a rare, admin-approved
        // event -- paid by the next comment in each script as a cold rebuild.
        //
        // A unit the store could not strip of its text is the erased text still
        // readable in wp_options, which is the same loss as a refused DELETE
        // above and is reported the same way rather than closing the request
        // over it.
        if ( $samples_removed || 1 === $page ) {
            if ( ! ANTISPAMWIZARD_Model_Store::delete_units( ANTISPAMWIZARD_Model_Store::unit_keys() ) ) {
                $items_retained = true;
            }
        }

        if ( 1 === $page && '' !== $hash ) {
            $deleted_form = ANTISPAMWIZARD_Spam_Log::delete_entries_by_email_hash( $hash );
            if ( $deleted_form > 0 ) {
                $items_removed = true;
            }
        }

        $messages = array();
        if ( $items_removed ) {
            $messages[] = __( 'AntiSpam Wizard spam-log entries, flagging reasons, form submissions, and training samples were removed.', 'antispam-wizard' );
        }
        if ( $items_retained ) {
            $messages[] = __( 'AntiSpam Wizard training samples could not be removed because of a database error. Please retry this erasure request.', 'antispam-wizard' );
        }

        return array(
            'items_removed'  => $items_removed,
            'items_retained' => $items_retained,
            'messages'       => $messages,
            'done'           => count( $comment_ids ) < self::COMMENTS_PER_PAGE,
        );
    }

    /**
     * Add the plugin's suggested privacy policy text.
     */
    public function add_privacy_policy_content(): void {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }

        $content = wp_kses_post(
            __(
                'AntiSpam Wizard analyzes comments and contact-form submissions to detect spam. When a comment is flagged, either by one of the plugin\'s checks or by a spam decision you make while moderating, the plugin stores a short fragment of the comment text, a SHA-256 hash of the commenter\'s IP address (the raw IP is never stored), and the reason the comment was flagged. When a contact-form submission is flagged, the plugin stores a short fragment of the submitted text, a SHA-256 hash of the submitter\'s IP address, and a SHA-256 hash of the submitter\'s email address (raw values are never stored). When a comment is used to train the spam filter, either from the plugin\'s Spam Log or by any Spam / Not Spam moderation decision made elsewhere in WordPress, the full text of that comment is stored as a training sample, together with a SHA-256 hash of the commenter\'s email address, until it is erased through a personal-data erasure request or the plugin is uninstalled, and it is included in personal-data exports. This data is used solely for spam detection and statistics. It is removed in response to a personal-data erasure request and when the plugin is uninstalled.',
                'antispam-wizard'
            )
        );

        wp_add_privacy_policy_content(
            __( 'AntiSpam Wizard', 'antispam-wizard' ),
            wpautop( $content )
        );
    }
}
