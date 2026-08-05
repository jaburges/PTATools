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
    
    // Org chart geometry, in the SVG's own coordinate space
    var ORG_CHART_LAYOUT = {
        margin: 20,
        gap: 10,
        columnWidth: 200,
        deptHeight: 60,
        roleHeight: 40,
        roleGap: 5,
        deptToRolesGap: 20
    };

    /**
     * Size the chart needs to draw everything at full scale. Gutters sit
     * between columns, so there are one fewer of them than there are columns —
     * counting one per column is what used to push the last department past
     * the right edge.
     */
    function orgChartNaturalSize(departments, roles) {
        var L = ORG_CHART_LAYOUT;
        var columns = Math.max(departments.length, 1);
        var tallest = 0;

        departments.forEach(function(dept) {
            var count = roles.filter(function(role) {
                return role.department_id == dept.id;
            }).length;
            if (count > tallest) {
                tallest = count;
            }
        });

        return {
            width: (2 * L.margin) + (columns * L.columnWidth) + ((columns - 1) * L.gap),
            height: Math.max(
                L.margin + L.deptHeight + L.deptToRolesGap
                    + (tallest * (L.roleHeight + L.roleGap)) + L.margin,
                200
            )
        };
    }

    function drawOrgChart(el, data, options) {
        var container = d3.select(el);
        container.selectAll("*").remove();

        var departments = (data && data.departments) || [];
        var roles = (data && data.roles) || [];
        var natural = orgChartNaturalSize(departments, roles);
        var available = el.getBoundingClientRect().width || natural.width;

        /* Shrink to fit a narrow container so no column is ever clipped, but
           never enlarge past full scale or the text balloons on wide screens.
           Matching the pixel height to natural height * scale means
           preserveAspectRatio settles on exactly this scale. */
        var scale = Math.min(1, available / natural.width);

        // The height attribute is a floor: grow past it rather than cut content off.
        var minHeight = parseInt(options && options.height, 10) || 400;
        var displayHeight = Math.max(minHeight, Math.ceil(natural.height * scale));

        var svg = container.append("svg")
            .attr("width", "100%")
            .attr("height", displayHeight)
            .attr("viewBox", "0 0 " + natural.width + " " + natural.height)
            .attr("preserveAspectRatio", "xMidYMin meet");

        renderSimpleOrgChart(svg, data, natural.width, natural.height, options);
    }

    // Global function for rendering org charts
    window.renderPTAOrgChart = function(containerId, data, options) {
        if (typeof d3 === 'undefined') {
            console.error('D3.js is required for org charts');
            return;
        }

        var el = document.getElementById(containerId);
        if (!el) {
            return;
        }

        drawOrgChart(el, data, options);

        // The scale comes from the measured container, so it has to be recomputed
        // when that width changes.
        if (!el.ptaResizeBound) {
            el.ptaResizeBound = true;
            var pending = null;
            window.addEventListener('resize', function() {
                clearTimeout(pending);
                pending = setTimeout(function() {
                    drawOrgChart(el, data, options);
                }, 150);
            });
        }
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
        
        // Simple layout: departments as boxes with roles beneath. Drawing happens
        // in the natural coordinate space that orgChartNaturalSize() computed, so
        // these are fixed and the SVG's viewBox handles fitting the container.
        var L = ORG_CHART_LAYOUT;
        var deptWidth = L.columnWidth;
        var deptHeight = L.deptHeight;
        var roleHeight = L.roleHeight;
        var margin = L.margin;

        // Group for departments
        var deptGroup = svg.append("g")
            .attr("class", "departments");

        departments.forEach(function(dept, i) {
            var x = margin + (i * (deptWidth + L.gap));
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
                var roleY = y + deptHeight + L.deptToRolesGap + (j * (roleHeight + L.roleGap));
                
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













