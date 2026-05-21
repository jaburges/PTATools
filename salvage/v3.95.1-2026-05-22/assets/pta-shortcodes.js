/**
 * PTA Shortcodes JavaScript
 */

(function($) {
    'use strict';
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        initPTAShortcodes();
    });
    
    function initPTAShortcodes() {
        initRoleCards();
        initOrgCharts();
        initSignupModal();
    }
    
    function initRoleCards() {
        $('.pta-role-item').on('click', function(e) {
            if ($(e.target).hasClass('pta-signup-btn') || $(e.target).closest('.pta-signup-btn').length) {
                return; // Let the signup button handler deal with it
            }
            $(this).toggleClass('expanded');
        });
    }
    
    function initOrgCharts() {
        // Placeholder - individual org chart shortcodes call renderPTAOrgChart directly
    }

    // ── Signup Modal ──

    function initSignupModal() {
        if (typeof ptaSignupConfig === 'undefined' || !ptaSignupConfig.enabled) {
            return;
        }

        $(document).on('click', '.pta-signup-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var roleName = $(this).data('role-name');
            var deptName = $(this).data('department-name');
            openSignupModal(roleName, deptName);
        });
    }

    function openSignupModal(roleName, deptName) {
        // Remove any existing modal
        $('#pta-signup-modal').remove();

        var modalHtml = '<div id="pta-signup-modal" class="pta-modal-overlay">'
            + '<div class="pta-modal-content">'
            + '<div class="pta-modal-header">'
            + '<h3>Sign Up: ' + $('<span>').text(roleName).html() + '</h3>'
            + '<button type="button" class="pta-modal-close">&times;</button>'
            + '</div>'
            + '<div class="pta-modal-body"><p>Loading form...</p></div>'
            + '</div></div>';

        $('body').append(modalHtml);
        $('#pta-signup-modal').fadeIn(200);

        // Close handlers
        $('#pta-signup-modal .pta-modal-close, #pta-signup-modal').on('click', function(e) {
            if (e.target === this) {
                $('#pta-signup-modal').fadeOut(200, function() { $(this).remove(); });
            }
        });

        // Load form via AJAX
        $.post(ptaSignupConfig.ajax_url, {
            action: 'pta_render_signup_form',
            nonce: ptaSignupConfig.nonce,
            role_name: roleName,
            department_name: deptName
        }, function(response) {
            if (response.success && response.data && response.data.html) {
                $('#pta-signup-modal .pta-modal-body').html(response.data.html);

                // Re-init Forminator if its JS needs to bind to new form elements
                if (typeof ForminatorFront !== 'undefined') {
                    try { ForminatorFront.init(); } catch(err) { /* ignore */ }
                }
            } else {
                $('#pta-signup-modal .pta-modal-body').html(
                    '<p class="pta-error">Unable to load the signup form. Please try again later.</p>'
                );
            }
        }).fail(function() {
            $('#pta-signup-modal .pta-modal-body').html(
                '<p class="pta-error">Unable to load the signup form. Please try again later.</p>'
            );
        });
    }

    // Expose globally so the D3 chart can call it
    window.ptaOpenSignupModal = openSignupModal;
    
    // Global function for rendering org charts
    window.renderPTAOrgChart = function(containerId, data, options) {
        if (typeof d3 === 'undefined') {
            console.error('D3.js is required for org charts');
            return;
        }
        
        var container = d3.select('#' + containerId);
        var width = container.node().getBoundingClientRect().width;
        var height = parseInt(options.height) || 400;
        
        // Clear any existing content
        container.selectAll("*").remove();
        
        var svg = container.append("svg")
            .attr("width", width)
            .attr("height", height);
        
        // Create a simple hierarchical layout
        renderSimpleOrgChart(svg, data, width, height, options);
    };
    
    function renderSimpleOrgChart(svg, data, width, height, options) {
        var departments = data.departments;
        var roles = data.roles;
        var assignments = data.assignments;
        
        if (!departments || departments.length === 0) {
            svg.append("text")
                .attr("x", width / 2)
                .attr("y", height / 2)
                .attr("text-anchor", "middle")
                .style("fill", "#999")
                .text("No organizational data available");
            return;
        }
        
        // Simple layout: departments as boxes with roles beneath
        var deptWidth = Math.min(200, (width - 40) / departments.length);
        var deptHeight = 60;
        var roleHeight = 40;
        var margin = 20;
        
        // Group for departments
        var deptGroup = svg.append("g")
            .attr("class", "departments");
        
        departments.forEach(function(dept, i) {
            var x = margin + (i * (deptWidth + 10));
            var y = margin;
            
            // Department box
            var deptBox = deptGroup.append("g")
                .attr("class", "department")
                .attr("transform", "translate(" + x + "," + y + ")");
            
            deptBox.append("rect")
                .attr("width", deptWidth)
                .attr("height", deptHeight)
                .attr("rx", 5)
                .style("fill", "#007cba")
                .style("stroke", "#005a87")
                .style("stroke-width", 1);
            
            deptBox.append("text")
                .attr("x", deptWidth / 2)
                .attr("y", 20)
                .attr("text-anchor", "middle")
                .style("fill", "white")
                .style("font-weight", "bold")
                .style("font-size", "12px")
                .text(dept.name);
            
            if (dept.vp) {
                deptBox.append("text")
                    .attr("x", deptWidth / 2)
                    .attr("y", 35)
                    .attr("text-anchor", "middle")
                    .style("fill", "white")
                    .style("font-size", "10px")
                    .text("VP: " + dept.vp);
            }
            
            if (dept.email) {
                var emailLink = deptBox.append("a")
                    .attr("href", "mailto:" + dept.email);
                emailLink.append("text")
                    .attr("x", deptWidth / 2)
                    .attr("y", 50)
                    .attr("text-anchor", "middle")
                    .style("fill", "#cce5ff")
                    .style("font-size", "9px")
                    .style("cursor", "pointer")
                    .style("text-decoration", "underline")
                    .text(dept.email);
            }
            
            // Department roles
            var deptRoles = roles.filter(function(role) {
                return role.department_id == dept.id;
            });
            
            deptRoles.forEach(function(role, j) {
                var roleY = y + deptHeight + 20 + (j * (roleHeight + 5));
                
                var roleBox = deptGroup.append("g")
                    .attr("class", "role")
                    .attr("transform", "translate(" + x + "," + roleY + ")");
                
                var fillColor = role.assigned_count >= role.max_occupants ? "#28a745" : 
                               role.assigned_count > 0 ? "#ffc107" : "#dc3545";
                
                roleBox.append("rect")
                    .attr("width", deptWidth)
                    .attr("height", roleHeight)
                    .attr("rx", 3)
                    .style("fill", fillColor)
                    .style("fill-opacity", 0.2)
                    .style("stroke", fillColor)
                    .style("stroke-width", 1);
                
                roleBox.append("text")
                    .attr("x", 5)
                    .attr("y", 15)
                    .style("font-size", "10px")
                    .style("font-weight", "bold")
                    .text(role.name);
                
                roleBox.append("text")
                    .attr("x", 5)
                    .attr("y", 30)
                    .style("font-size", "9px")
                    .style("fill", "#666")
                    .text(role.assigned_count + "/" + role.max_occupants + " filled");
                
                // Add assignments
                var roleAssignments = assignments.filter(function(assignment) {
                    return assignment.role_id == role.id;
                });
                
                if (roleAssignments.length > 0) {
                    roleBox.append("title")
                        .text("Assigned to: " + roleAssignments.map(function(a) {
                            return a.user_name;
                        }).join(", "));
                }
            });
        });
        
        // Store role data on each role group for click handling
        svg.selectAll(".role").each(function(d, i) {
            var allRoles = [];
            departments.forEach(function(dept) {
                var deptRoles = roles.filter(function(r) { return r.department_id == dept.id; });
                deptRoles.forEach(function(r) {
                    allRoles.push({ name: r.name, dept: dept.name, assigned: r.assigned_count, max: r.max_occupants });
                });
            });
            if (allRoles[i]) {
                d3.select(this).datum(allRoles[i]);
            }
        });

        if (options.interactive) {
            svg.selectAll(".role")
                .style("cursor", "pointer")
                .on("click", function(event, d) {
                    if (d && typeof window.ptaOpenSignupModal === 'function'
                        && typeof ptaSignupConfig !== 'undefined' && ptaSignupConfig.enabled) {
                        var openOnly = ptaSignupConfig.open_roles_only;
                        if (!openOnly || d.assigned < d.max) {
                            window.ptaOpenSignupModal(d.name, d.dept);
                        }
                    }
                });
        }
    }
    
})(jQuery);













