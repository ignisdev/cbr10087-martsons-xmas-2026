/**
 * Britvic Promotion Manager Admin JavaScript
 */

jQuery(document).ready(function($) {
    'use strict';

    // Prize generation form handling
    $('#add-prize-type').on('click', function() {
        const template = `
            <div class="prize-type-row">
                <table class="form-table">
                    <tr>
                        <th><label>Prize Type</label></th>
                        <td><input type="text" class="regular-text prize-name" placeholder="e.g., Free Drink" /></td>
                    </tr>
                    <tr>
                        <th><label>Prize Value</label></th>
                        <td><input type="text" class="regular-text prize-value" placeholder="e.g., £5.00 (optional)" /></td>
                    </tr>
                    <tr>
                        <th><label>Code Prefix</label></th>
                        <td><input type="text" class="small-text prize-prefix" placeholder="e.g., FS" maxlength="5" /></td>
                    </tr>
                    <tr>
                        <th><label>Weight (%)</label></th>
                        <td><input type="number" class="small-text prize-weight" min="1" max="100" value="50" /></td>
                    </tr>
                </table>
                <button type="button" class="button remove-prize-type">Remove Prize Type</button>
            </div>
        `;
        $('#prize-types').append(template);
    });

    // Remove prize type
    $(document).on('click', '.remove-prize-type', function() {
        $(this).closest('.prize-type-row').remove();
    });

    // Generate prizes AJAX
    $('#generate-prizes-form').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $button = $form.find('input[type="submit"]');
        const originalText = $button.val();
        
        // Collect prize types data
        const prizeTypes = [];
        $('.prize-type-row').each(function() {
            const $row = $(this);
            const name = $row.find('.prize-name').val();
            const value = $row.find('.prize-value').val();
            const prefix = $row.find('.prize-prefix').val();
            const weight = parseInt($row.find('.prize-weight').val()) || 50;
            
            if (name && prefix) {
                prizeTypes.push({
                    name: name,
                    value: value,
                    code_prefix: prefix,
                    weight: weight
                });
            }
        });
        
        if (prizeTypes.length === 0) {
            alert('Please add at least one prize type.');
            return;
        }
        
        // Disable form
        $button.val('Generating...').prop('disabled', true);
        $form.addClass('loading');
        
        // AJAX request
        $.post(bpmAjax.ajaxUrl, {
            action: 'bpm_generate_prizes',
            nonce: bpmAjax.nonce,
            start_date: $('#start_date').val(),
            end_date: $('#end_date').val(),
            start_time: $('#start_time').val(),
            end_time: $('#end_time').val(),
            num_dates: $('#num_dates').val(),
            competition_id: $('#competition_id').val(),
            prizes_data: JSON.stringify({
                competition_id: $('#competition_id').val(),
                types: prizeTypes
            })
        }, function(response) {
            if (response.success) {
                alert('Prizes generated successfully! ' + response.data.created_count + ' prizes created.');
                if (response.data.csv_file) {
                    window.location.href = response.data.csv_file;
                }
                location.reload();
            } else {
                alert('Error: ' + (response.data.message || 'Unknown error occurred'));
            }
        }).fail(function() {
            alert('Network error occurred. Please try again.');
        }).always(function() {
            $button.val(originalText).prop('disabled', false);
            $form.removeClass('loading');
        });
    });

    // Page toggle functionality
    $('.toggle-pages').on('click', function() {
        const $button = $(this);
        const action = $button.data('action');
        const competitionId = $button.data('competition-id');
        const originalText = $button.text();
        
        $button.text('Processing...').prop('disabled', true);
        
        $.post(bpmAjax.ajaxUrl, {
            action: 'bpm_toggle_pages',
            nonce: bpmAjax.nonce,
            competition_id: competitionId,
            page_action: action
        }, function(response) {
            if (response.success) {
                alert('Pages updated successfully!');
                location.reload();
            } else {
                alert('Error: ' + (response.data.message || 'Unknown error occurred'));
            }
        }).fail(function() {
            alert('Network error occurred. Please try again.');
        }).always(function() {
            $button.text(originalText).prop('disabled', false);
        });
    });

    // Email template management
    $('#add-email-template').on('click', function() {
        const template = `
            <div class="email-template-row">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label>Prize Match</label>
                        </th>
                        <td>
                            <input type="text" class="regular-text prize-match" placeholder="e.g., Vintage Inns Voucher" />
                            <p class="description">Exact prize name or keyword to match (case insensitive)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label>Email Subject</label>
                        </th>
                        <td>
                            <input type="text" class="regular-text email-subject" placeholder="You've won a {prize_name}!" />
                            <p class="description">Available placeholders: {prize_name}, {prize_value}, {winner_name}, {code}</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label>Email Template</label>
                        </th>
                        <td>
                            <textarea class="large-text email-template" rows="8" placeholder="Congratulations! You've won a {prize_name}..."></textarea>
                            <p class="description">HTML is supported. Use placeholders: {prize_name}, {prize_value}, {winner_name}, {code}, {claim_instructions}</p>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <button type="button" class="button remove-email-template">Remove Template</button>
                        </td>
                    </tr>
                </table>
            </div>
        `;
        $('#email-templates').append(template);
    });

    // Remove email template
    $(document).on('click', '.remove-email-template', function() {
        $(this).closest('.email-template-row').remove();
    });

    // Save email templates
    $('#save-email-templates').on('click', function() {
        const $button = $(this);
        const competitionId = $button.data('competition-id');
        
        if (!competitionId) {
            alert('Please select a competition first.');
            return;
        }
        
        const templates = [];
        $('.email-template-row').each(function() {
            const $row = $(this);
            const match = $row.find('.prize-match').val();
            const subject = $row.find('.email-subject').val();
            const template = $row.find('.email-template').val();
            
            if (match && subject && template) {
                templates.push({
                    prize_match: match,
                    email_subject: subject,
                    email_template: template
                });
            }
        });
        
        $button.prop('disabled', true).text('Saving...');
        
        $.post(bpmAjax.ajaxUrl, {
            action: 'bpm_save_email_templates',
            nonce: bpmAjax.nonce,
            competition_id: competitionId,
            templates: JSON.stringify(templates)
        }, function(response) {
            if (response.success) {
                alert('Email templates saved successfully!');
            } else {
                alert('Error: ' + (response.data.message || 'Unknown error occurred'));
            }
        }).always(function() {
            $button.prop('disabled', false).text('Save Email Templates');
        });
    });

    // Email preview functionality
    $('#preview-email').on('click', function() {
        const testPrize = $('#test_prize').val();
        if (!testPrize) {
            alert('Please enter a test prize name.');
            return;
        }
        
        // Preview email template logic here
        $('#email-preview-content').html('<p>Email preview functionality will be implemented here.</p>').show();
    });

    // Note: Send test email functionality is handled in notifications.php inline JavaScript
    // to avoid conflicts with page-specific implementations
});