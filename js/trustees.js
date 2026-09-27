/* Trustee networks — interactive board-membership social graph. */

$(function () {
    var $stage  = $("#trustee-stage");
    var $ctrls  = $("#trustee-controls");
    var $depth  = $("#trustee-depth-toggle");
    var $meta   = $("#trustee-meta");
    var $detail = $("#trustee-detail");
    var $browse = $("#trustee-browse");

    var currentKey = null;

    // -- depth toggle ---------------------------------------------------
    $depth.on("change", function () {
        if (currentKey) loadGraph(currentKey);
    });

    // -- URL bookmarking ------------------------------------------------
    function syncUrl(key, depth) {
        var u = "/trustees.php?key=" + encodeURIComponent(key) + "&depth=" + depth;
        history.replaceState({ key: key, depth: depth }, "", u);
    }

    // -- main loader ----------------------------------------------------
    function loadGraph(key) {
        currentKey = key;
        var depth = $depth.is(":checked") ? 2 : 1;
        $browse.hide();
        $stage.html('<p class="bacon-loading">Loading…</p>');
        $.ajax({
            url: "/data.php",
            data: { trustees_graph: key, depth: depth },
            dataType: "json",
            global: false,
            success: function (data) {
                if (data.error) {
                    $stage.html('<p class="nobacon">Unknown trustee — try the autocomplete.</p>');
                    return;
                }
                $ctrls.prop("hidden", false);
                syncUrl(key, depth);
                $meta.text(
                    data.nodes.length + " people · " +
                    data.links.length + " co-board edges · depth " + data.depth
                );
                renderGraph(data);
                renderDetail(data);
            },
            error: function () {
                $stage.html('<p class="nobacon">Network error — try again.</p>');
            }
        });
    }

    // -- browse panel ---------------------------------------------------
    function loadDirectory() {
        $.ajax({
            url: "/data.php",
            data: { trustees_directory: 1 },
            dataType: "json",
            global: false,
            success: renderDirectory
        });
    }

    function trusteeLink(key, name, boards) {
        return $("<a/>").attr("href", "#")
            .append($("<span/>").addClass("browse-name").text(name))
            .append($("<span/>").addClass("browse-count").text(boards > 1 ? boards + " boards" : "1 board"))
            .on("click", function (e) {
                e.preventDefault();
                loadGraph(key);
                $("html, body").animate({ scrollTop: $stage.offset().top - 24 }, 200);
            });
    }

    function renderDirectory(d) {
        var $bridges = $("#trustee-browse-bridges").empty();
        d.bridges.forEach(function (b) {
            $bridges.append($("<li/>").append(trusteeLink(b[0], b[1], b[2])));
        });

        var $museums = $("#trustee-browse-museums").empty();
        d.museums.forEach(function (m) {
            var $row = $("<a/>").attr("href", "#").addClass("museum-row")
                .append($("<span/>").addClass("browse-name").text(m.name))
                .append($("<span/>").addClass("browse-count").text(m.city + " · " + m.trustee_count + " trustees"));
            var $li = $("<li/>").append($row);
            $row.on("click", function (e) {
                e.preventDefault();
                toggleMuseum($li, m.ein);
            });
            $museums.append($li);
        });
    }

    function toggleMuseum($li, ein) {
        var $existing = $li.find(".museum-trustees");
        if ($existing.length) { $existing.remove(); $li.removeClass("expanded"); return; }
        $li.addClass("expanded");
        var $panel = $("<div/>").addClass("museum-trustees").text("Loading…").appendTo($li);
        $.ajax({
            url: "/data.php",
            data: { museum_trustees: ein },
            dataType: "json",
            global: false,
            success: function (rows) {
                $panel.empty();
                if (!rows.length) { $panel.text("No trustees on file."); return; }
                rows.forEach(function (r) {
                    $panel.append(trusteeLink(r[0], r[1], r[2]));
                });
            },
            error: function () { $panel.text("Error loading trustees."); }
        });
    }

    $("#trustee-browse-back").on("click", function (e) {
        e.preventDefault();
        currentKey = null;
        $stage.empty();
        $detail.prop("hidden", true).empty();
        $ctrls.prop("hidden", true);
        history.replaceState({}, "", "/trustees.php");
        $browse.show();
        $("html, body").animate({ scrollTop: 0 }, 200);
    });

    // -- D3 force graph -------------------------------------------------
    var groupColor = { 0: "#fce77d", 1: "#5b9bd5", 2: "#888" };

    // Build an EIN -> museum-name lookup from the nodes' museum payloads so
    // the graph and detail panel can say "fellow trustee at MoMA" instead of
    // a bare EIN.
    function buildEinIndex(nodes) {
        var idx = {};
        nodes.forEach(function (n) {
            (n.museums || []).forEach(function (m) {
                if (m && m.ein) idx[m.ein] = m.name;
            });
        });
        return idx;
    }

    function sharedMuseumNames(linkMuseums, einIdx) {
        return (linkMuseums || []).map(function (e) { return einIdx[e] || e; });
    }

    function renderGraph(data) {
        var w = 1000, h = 600;
        var isMobile = /Mobi|Android/i.test(navigator.userAgent);
        if (isMobile) { w = 420; h = 600; }
        var links = data.links.map(function (l) { return Object.assign({}, l); });
        var nodes = data.nodes.map(function (n) { return Object.assign({}, n); });
        var einIdx = buildEinIndex(nodes);
        var nameById = {};
        nodes.forEach(function (n) { nameById[n.id] = n.name; });

        var sim = d3.forceSimulation(nodes)
            .force("link",   d3.forceLink(links).id(function (d) { return d.id; }).distance(60).strength(0.4))
            .force("charge", d3.forceManyBody().strength(-160))
            .force("center", d3.forceCenter(w / 2, h / 2))
            .force("collide", d3.forceCollide(14));

        var svg = d3.create("svg")
            .attr("viewBox", [0, 0, w, h])
            .attr("role", "img")
            .attr("aria-label",
                "Trustee co-board network: " + nodes.length + " people, " +
                links.length + " co-board connections. Use Tab to move between trustees and Enter to open details.");

        var link = svg.append("g").attr("stroke", "#444").attr("stroke-opacity", 0.6)
            .selectAll("line").data(links).enter().append("line")
            .attr("stroke-width", function (d) { return Math.min(4, 1 + 0.5 * d.museums.length); });
        link.append("title").text(function (d) {
            var s = nameById[d.source.id || d.source] || (d.source.id || d.source);
            var t = nameById[d.target.id || d.target] || (d.target.id || d.target);
            var names = sharedMuseumNames(d.museums, einIdx);
            return s + " & " + t + " — fellow trustees at " + names.join(", ");
        });

        function showHoverLabel(el, name) {
            $(".node-hover-label").remove();
            var rect = el.getBoundingClientRect();
            $("<div/>").attr("class", "node-hover-label")
                .text(name)
                .css({ position: "absolute",
                       left: rect.right + window.pageXOffset + 8,
                       top:  rect.top   + window.pageYOffset + rect.height / 2 - 10 })
                .appendTo("body");
        }
        function hideHoverLabel() { $(".node-hover-label").remove(); }

        var node = svg.append("g")
            .selectAll("g").data(nodes).enter().append("g")
            .style("cursor", "pointer")
            .attr("tabindex", 0)
            .attr("role", "button")
            .attr("aria-label", function (d) {
                var role = d.group === 0 ? " (center of network)" :
                           (d.group === 1 ? " (direct co-board)" : " (2nd-degree connection)");
                var boards = d.board_count > 1 ? ", on " + d.board_count + " boards" : "";
                return d.name + role + boards;
            })
            .on("click", function (d) {
                if (d3.event && d3.event.stopPropagation) d3.event.stopPropagation();
                showNodeModal(d, this, { nodes: data.nodes, links: data.links, einIdx: einIdx });
            })
            .on("keydown", function (d) {
                var k = d3.event.key;
                if (k === "Enter" || k === " " || k === "Spacebar") {
                    d3.event.preventDefault();
                    showNodeModal(d, this, { nodes: data.nodes, links: data.links, einIdx: einIdx });
                }
            });

        node.append("circle")
            .attr("r", function (d) { return d.group === 0 ? 14 : (d.board_count > 1 ? 10 : 7); })
            .attr("fill", function (d) { return groupColor[d.group] || "#888"; })
            .attr("stroke", "white").attr("stroke-width", function (d) { return d.group === 0 ? 3 : 1; });

        // Only label the center + multi-board folks by default; everyone else
        // shows on hover or keyboard focus.
        node.filter(function (d) { return d.group === 0 || d.board_count > 1; })
            .append("text")
            .attr("x", function (d) { return d.group === 0 ? 18 : 14; })
            .attr("y", 4)
            .attr("fill", "white").attr("font-size", function (d) { return d.group === 0 ? 13 : 11; })
            .text(function (d) { return d.name; });

        // Hover + focus label so keyboard users get the same name preview.
        node.on("mouseenter.label", function (d) { showHoverLabel(this, d.name); })
            .on("mouseleave.label", hideHoverLabel)
            .on("focus.label", function (d) { showHoverLabel(this, d.name); })
            .on("blur.label", hideHoverLabel);

        function renderTick() {
            link.attr("x1", function (d) { return d.source.x; })
                .attr("y1", function (d) { return d.source.y; })
                .attr("x2", function (d) { return d.target.x; })
                .attr("y2", function (d) { return d.target.y; });
            node.attr("transform", function (d) {
                d.x = Math.max(8, Math.min(w - 8, d.x));
                d.y = Math.max(8, Math.min(h - 8, d.y));
                return "translate(" + d.x + "," + d.y + ")";
            });
        }

        // Respect prefers-reduced-motion: run the simulation to steady state
        // synchronously and render a single static frame instead of animating.
        var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reducedMotion) {
            for (var ti = 0; ti < 300; ti++) sim.tick();
            sim.stop();
            renderTick();
        } else {
            sim.on("tick", renderTick);
        }

        $stage.empty().append(svg.node());
    }

    // -- Node modal (anchored card, mirrors artist-networks UX) ---------
    var modalReturnFocus = null;
    function closeNodeModal() {
        $(".node-modal").remove();
        $(document).off("keydown.trusteeModal");
        if (modalReturnFocus && typeof modalReturnFocus.focus === "function") {
            try { modalReturnFocus.focus(); } catch (_) {}
        }
        modalReturnFocus = null;
    }
    function showNodeModal(d, nodeEl, ctx) {
        var center = ctx.nodes.find(function (n) { return n.group === 0; });
        var einIdx = ctx.einIdx;
        modalReturnFocus = nodeEl;

        var titleId = "trustee-modal-title-" + Date.now();
        var $modal = $("<div/>").addClass("node-modal trustee-modal")
            .attr("role", "dialog")
            .attr("aria-modal", "true")
            .attr("aria-labelledby", titleId)
            .attr("tabindex", -1);

        // Title line: name (clickable to recenter the network on this person).
        var $titleA = $("<a/>").attr("href", "#").addClass("artist-link")
            .attr("id", titleId).text(d.name)
            .on("click", function (e) {
                e.preventDefault();
                closeNodeModal();
                loadGraph(d.id);
            });
        $modal.append($("<div/>").addClass("rel-line").append($titleA));

        // Relationship line: how this node relates to the center.
        if (center && d.id !== center.id) {
            var sharedEins = [];
            for (var i = 0; i < ctx.links.length; i++) {
                var l = ctx.links[i];
                var s = l.source.id || l.source;
                var t = l.target.id || l.target;
                if ((s === center.id && t === d.id) || (t === center.id && s === d.id)) {
                    sharedEins = l.museums || []; break;
                }
            }
            var relText = '';
            if (sharedEins.length) {
                relText = 'Fellow trustee at ' + sharedMuseumNames(sharedEins, einIdx).join(', ')
                        + ' with ' + center.name + '.';
            } else {
                // No direct edge — must be 2nd-degree. Find a 1st-degree
                // intermediary X such that center—X and X—d both exist.
                var firstHopByX = {};
                ctx.links.forEach(function (l) {
                    var s = l.source.id || l.source, t = l.target.id || l.target;
                    if (s === center.id) firstHopByX[t] = l;
                    else if (t === center.id) firstHopByX[s] = l;
                });
                for (var j = 0; j < ctx.links.length; j++) {
                    var l2 = ctx.links[j];
                    var s2 = l2.source.id || l2.source, t2 = l2.target.id || l2.target;
                    var x = null, hop2 = null;
                    if (s2 === d.id && firstHopByX[t2]) { x = t2; hop2 = l2; }
                    else if (t2 === d.id && firstHopByX[s2]) { x = s2; hop2 = l2; }
                    if (x) {
                        var xNode = ctx.nodes.find(function (n) { return n.id === x; });
                        var hop1 = firstHopByX[x];
                        var via1 = sharedMuseumNames(hop1.museums, einIdx).join(', ');
                        var via2 = sharedMuseumNames(hop2.museums, einIdx).join(', ');
                        relText = 'Via ' + (xNode ? xNode.name : x)
                                + ' — co-board with ' + center.name + ' at ' + via1
                                + ', and with ' + d.name + ' at ' + via2 + '.';
                        break;
                    }
                }
            }
            if (relText) $modal.append($("<p/>").addClass("rel-info").text(relText));
        } else if (d.id === (center && center.id)) {
            $modal.append($("<p/>").addClass("rel-info").text('Center of this network.'));
        }

        // Boards list — ProPublica-linked, with tenure year range.
        if (d.museums && d.museums.length) {
            $modal.append($("<h4/>").addClass("modal-rels-title").text('Boards'));
            var $ul = $("<ul/>").addClass("modal-rels-list");
            d.museums.forEach(function (m) {
                var $li = $("<li/>");
                if (typeof m === "string") { $li.text(m); $ul.append($li); return; }
                $li.append($("<a/>").attr("href", m.url).attr("target", "_blank")
                                  .attr("rel", "noopener").text(m.name));
                // Tenure suffix: "(2018–2024)" current, "(2014–2023, departed)" past.
                var suffix = '';
                if (m.first_year && m.last_year) {
                    suffix = (m.first_year === m.last_year)
                        ? ' (' + m.first_year + ')'
                        : ' (' + m.first_year + '–' + m.last_year + ')';
                }
                if (m.departed) suffix = suffix.replace(/\)$/, ', departed)');
                if (suffix) $li.append($("<span/>").addClass("modal-tenure").text(suffix));
                $ul.append($li);
            });
            $modal.append($ul);
        }

        // Board-count chip if multi-board.
        if (d.board_count > 1) {
            $modal.append($("<p/>").addClass("rel-info").text('On ' + d.board_count + ' boards total.'));
        }

        // Close button — real <button> so keyboard + screen-reader users get
        // the expected affordance.
        var $close = $("<button/>").attr("type", "button")
            .addClass("close-modal").attr("aria-label", "Close trustee details").html("&times;")
            .on("click", function (e) { e.preventDefault(); closeNodeModal(); });
        $modal.append($close);

        // White background like artist-networks modals.
        $modal.css({ "background-color": "white" });

        // Position anchored to the clicked node's on-page rect.
        $(".node-modal").remove();
        $modal.css({ top: -9999, left: -9999 }).appendTo("body");
        var rect = nodeEl.getBoundingClientRect();
        var mw = $modal[0].offsetWidth;
        var mh = $modal[0].offsetHeight;
        var nodeCenterX = rect.left + rect.width / 2 + window.pageXOffset;
        var nodeTop     = rect.top + window.pageYOffset;
        var gap = 8;
        var top  = nodeTop - mh - gap;
        if (top < window.pageYOffset + 4) top = nodeTop + rect.height + gap;
        var left = nodeCenterX - mw / 2;
        var maxLeft = window.pageXOffset + document.documentElement.clientWidth - mw - 4;
        if (left < window.pageXOffset + 4) left = window.pageXOffset + 4;
        if (left > maxLeft) left = maxLeft;
        $modal.css({ top: top, left: left, visibility: "visible" });

        // Move focus into the modal so keyboard users land in it.
        $modal[0].focus();

        // Esc dismisses; Tab is trapped within the modal's focusable elements.
        $(document).off("keydown.trusteeModal").on("keydown.trusteeModal", function (e) {
            if (e.key === "Escape" || e.key === "Esc") {
                e.preventDefault(); e.stopPropagation();
                closeNodeModal();
                return;
            }
            if (e.key !== "Tab") return;
            var focusables = $modal.find('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])').toArray();
            if (!focusables.length) return;
            var first = focusables[0], last = focusables[focusables.length - 1];
            var active = document.activeElement;
            if (e.shiftKey && (active === first || active === $modal[0])) {
                e.preventDefault(); last.focus();
            } else if (!e.shiftKey && active === last) {
                e.preventDefault(); first.focus();
            }
        });
    }

    // Dismiss the modal when clicking anywhere outside it. Node-click
    // handlers stop propagation so they don't trigger this.
    $(document).on("click.trusteeModalDismiss", function (e) {
        if (!$(e.target).closest(".node-modal").length && $(".node-modal").length) {
            closeNodeModal();
        }
    });

    // -- Detail panel (board list for focused node) ---------------------
    function renderDetail(data) {
        var focus = data.focus || data.nodes.find(function (n) { return n.group === 0; });
        if (!focus) { $detail.prop("hidden", true).empty(); return; }
        var einIdx = buildEinIndex(data.nodes);
        var $box = $("<div/>");
        $box.append($("<h2/>").text(focus.name));
        if (focus.museums && focus.museums.length) {
            $box.append($("<h3/>").text("Boards"));
            var $ul = $("<ul/>").addClass("trustees-board-list");
            focus.museums.forEach(function (m) {
                var $li = $("<li/>");
                if (typeof m === "string") { $li.text(m); $ul.append($li); return; }
                $li.append($("<a/>").attr("href", m.url).attr("target", "_blank").attr("rel", "noopener").text(m.name));
                var suffix = '';
                if (m.first_year && m.last_year) {
                    suffix = (m.first_year === m.last_year)
                        ? ' (' + m.first_year + ')'
                        : ' (' + m.first_year + '–' + m.last_year + ')';
                }
                if (m.departed) suffix = suffix.replace(/\)$/, ', departed)');
                if (suffix) $li.append($("<span/>").addClass("trustees-tenure").text(suffix));
                $ul.append($li);
            });
            $box.append($ul);
        }
        // Co-board neighbors of focus, with the museums that bind them.
        var sharedByNeighbor = {}; // id => [ein, ein, ...]
        data.links.forEach(function (l) {
            var s = l.source.id || l.source;
            var t = l.target.id || l.target;
            if (s === focus.id)      sharedByNeighbor[t] = l.museums || [];
            else if (t === focus.id) sharedByNeighbor[s] = l.museums || [];
        });
        var neighbors = data.nodes.filter(function (n) { return sharedByNeighbor[n.id]; });
        if (neighbors.length) {
            $box.append($("<h3/>").text("Co-board with"));
            var $ul2 = $("<ul/>").addClass("trustees-board-list");
            neighbors.forEach(function (n) {
                var $li = $("<li/>");
                var $a = $("<a/>").attr("href", "#").text(n.name)
                    .on("click", function (e) {
                        e.preventDefault();
                        loadGraph(n.id);
                    });
                $li.append($a);
                var names = sharedMuseumNames(sharedByNeighbor[n.id], einIdx);
                if (names.length) {
                    $li.append($("<span/>").addClass("trustees-shared")
                        .text(" — fellow trustee at " + names.join(", ")));
                }
                if (n.board_count > 1) {
                    $li.append($("<span/>").addClass("trustees-boardcount")
                        .text(" (" + n.board_count + " boards)"));
                }
                $ul2.append($li);
            });
            $box.append($ul2);
        }
        $detail.prop("hidden", false).empty().append($box);
    }

    // -- bootstrap from URL --------------------------------------------
    var params = new URLSearchParams(window.location.search);
    var initialKey = params.get("key");
    var initialDepth = params.get("depth");
    if (initialDepth === "2") $depth.prop("checked", true);
    loadDirectory();
    if (initialKey) loadGraph(initialKey);
});
