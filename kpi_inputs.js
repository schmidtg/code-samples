$(document).ready(function() {

    $(function() {
        var dates = $( "#date_start" ).datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            numberOfMonths: 1,
            onSelect: function( selectedDate ) {
                var option = this.id == "date_start" ? "minDate" : "maxDate",
                    instance = $( this ).data( "datepicker" ),
                    date = $.datepicker.parseDate(
                        instance.settings.dateFormat ||
                        $.datepicker._defaults.dateFormat,
                        selectedDate, instance.settings );
                dates.not( this ).datepicker( "option", option, date );

                KPI_input.checkIfDatePresent();
            }
        });
    });

    $('#wait').hide();

    /**
     *  Get/put visa transaction numbers and CB numbers for a day in the month
     *
     *  @author     Graham Schmidt
     *  @created    @2012-08-21
     */
    var KPI_input = {
        init: function( config ) {
            this.config = config;

            this.setupTemplates();
            this.bindEvents();
            this.checkIfDatePresent();
            // Hide on load, show once date is picked
            // (see datepicker codeblock at top)
            this.hideInputs();

            // Setup ajax calls with defaults (send to controller)
            $.ajaxSetup({
                url: '/reports/marketing/kpi_inputs.php',
                type: 'POST'
            });
        },

        bindEvents: function() {
            this.config.inputFields.on( 'change', '.select_short', this.captureInput );
            this.config.viewMonthlyList.on( 'click', this.processMonthlyList );
        },

        setupTemplates: function() {
            this.config.monthlyListTemplate = Handlebars.compile( this.config.monthlyListTemplate );

            Handlebars.registerHelper('new_group', function() {
                return this.product_id === 100;
            });
        },

        // Populate Input fields from DB
        populateInput: function() {
            var self = KPI_input,
                response = '';

            this.clearInputs();
            response = this.get();

            response.then( function( results ) {
                // Populate each input with the transaction amounts
                // and remove any existing warnings
                self.config.throbber.hide();
                if (results) {
                    $.each(results, function(key, val) {
                        self.config.transactionCells.find("[data-pid = '" + key + "']").val(val.count_trans).next('span.warning-color').remove();
                        self.config.cbCells.find("[data-pid = '" + key + "']").val(val.count_cb).next('span.warning-color').remove();
                    });

                // Clear inputs and display an error message
                } else {
                    self.clearInputs();
                    self.config.transactionCells.append( "<span class='warning-color'>Sorry, you cannot enter transactions for this date yet.</span>" );
                    self.config.cbCells.append( "<span class='warning-color'>Sorry, you cannot enter CBs for this date yet.</span>" );
                }
            });
        },

        // Get/select the transactions from the DB
        get: function() {
            var self = KPI_input;

            return $.ajax({
                data: {
                    report_day: self.config.date_start.val()
                    , type: 'get'
                },
                dataType: 'json',
                beforeSend: function() {
                    self.config.throbber.show();
                }
            }).promise();
        },

        // Update/write a transaction to the DB
        captureInput: function() {
            var self = KPI_input,
                $this = $(this),
                response = '';

            // Only want numbers
            if ( self.validInput( $this.val() ) ) {
                response = self.put( $this );

                // Use deferreds in case we want to re-use response
                response.then(function( results ) {
                    $this.next('span.warning-color').remove();
                    $this.after("<span class='warning-color'>Updated.</span>");
                });

            } else {
                $this.next('span.warning-color').remove();
                $this.after("<span class='warning-color'>Must be a number.</span>");
            }
        },

        // Async call to update the DB, return a promise()
        put: function( $this ) {
            var self = KPI_input,
                field_name = self.getDbFieldName( $this );

            return $.ajax({
                data: {
                    count: $.trim($this.val())
                    , field_name: field_name
                    , product_id: $this.data('pid')
                    , report_day: self.config.date_start.val()
                    , type: 'put'
                }
            }).promise();
        },

        /**
         * Determine field name based on class grouped under
         */
        getDbFieldName: function( $this ) {
            var type = $this.parent('td').attr('class');

            return ( type === this.config.transactionCell
                    ? 'num_transactions_for_day_visa_only'
                    : 'num_chargebacks_for_day_visa_only'
            );
        },

        // Update/write a transaction to the DB
        processMonthlyList: function(e) {
            var self = KPI_input,
                response = '';

            // Remove table and update link text
            if ( self.config.monthlyList.children().is(':visible') ) {
                self.config.viewMonthlyList.text('Show List of Inputs for Month');
                self.config.monthlyList.empty();

            // Show table and update link text
            } else {
                self.config.viewMonthlyList.text('Hide List of Inputs for Month');

                // Use deferreds in case we want to re-use response
                response = self.getFullList();
                response.then(function( results ) {
                    self.config.throbber.hide();
                    self.config.monthlyList.empty();

                    if ( results ) {
                        self.config.monthlyList.append( self.config.monthlyListTemplate( results ) );
                    } else {
                        self.config.monthlyList.append('<p>Nothing returned.</p>');
                    }
                });
            }

            e.preventDefault();
        },

        getFullList: function() {
            var self = KPI_input,
                report_day = self.config.date_start.val().substr(0,7);

            return $.ajax({
                data: {
                    report_day: report_day
                    , type: 'getMonthlyList'
                },
                dataType: 'json',
                beforeSend: function() {
                    self.config.throbber.show();
                }
            }).promise();
        },

        // ***NOTE***
        // Called in the datepicker function above
        checkIfDatePresent: function() {
            if ( this.config.date_start.val() !== '' ) {
                this.config.inputFields.prev('span.warning-color').remove()
                this.showInputs();
                this.populateInput();
                return true;
            } else {
                this.config.inputFields.before( "<span class='warning-color'>Please provide a date.</span>" );
            }
        },

        validInput: function( input ) {
            return !isNaN( input );
        },

        clearInputs: function() {
            var self = KPI_input;

            // Clear values and remove any warning text
            $.each(self.config.inputs, function(key, val) {
                $(val).val('').next('span.warning-color').remove();
            });
        },

        hideInputs: function() {
            this.config.inputFields.hide();
            this.config.transactionCells.hide();
            this.config.cbCells.hide();
        },

        showInputs: function() {
            this.config.inputFields.show();
            this.config.transactionCells.show();
            this.config.cbCells.show();
        }
    }

    // Use a config object to instantiate and keep DOM-related stuff separate
    KPI_input.init({
        date_start: $('#date_start'),
        inputFields: $('#inputFields'),
        transactionCells: $('.transaction_cell'),
        transactionCell: 'transaction_cell',
        cbCells: $('.cb_cell'),
        cbCell: 'cb_cell',
        throbber: $('#wait'),
        inputs: $('.select_short'),
        monthlyList: $('#monthly_list'),
        viewMonthlyList: $('#view_monthly_list'),
        monthlyListTemplate: $('#monthly_list_template').html()
    });
});
