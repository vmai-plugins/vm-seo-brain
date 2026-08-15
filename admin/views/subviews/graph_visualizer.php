<?php
defined( 'ABSPATH' ) || exit;

/**
 * Subview: Knowledge Graph Visualizer (D3.js).
 */
?>
<article class="vmsb-card vmsb-card-wide" style="min-height: 600px;">
	<div class="vmsb-flex-space" style="margin-bottom: 20px;">
		<div>
			<h2 style="font-family:var(--serif);">Site Semantic Mesh</h2>
			<p class="vmsb-note">A real-time visualization of how your posts, categories, and entities are interconnected.</p>
		</div>
		<div style="display:flex; gap:10px;">
			<button class="vmsb-mini-btn" id="vmsb-refresh-graph">Refresh View</button>
			<button class="vmsb-mini-btn" data-vmsb="graph-sync">Rebuild Data</button>
		</div>
	</div>

	<div id="vmsb-graph-container" style="width: 100%; height: 500px; background: var(--ink); border: 1px solid var(--line); border-radius: 12px; position: relative; overflow: hidden;">
		<div id="vmsb-graph-loading" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 10;">
			<div class="vmsb-pulse-indicator"><span class="vmsb-dot vmsb-dot-gold"></span> <span>Synthesizing Mesh...</span></div>
		</div>
		<svg id="vmsb-graph-svg" style="width: 100%; height: 100%; cursor: grab;"></svg>
	</div>

	<div class="vmsb-legend" style="margin-top:20px; display:flex; gap:20px; justify-content:center;">
		<div class="vmsb-legend-item"><i style="background: var(--accent-blue);"></i> <span>Posts/Pages</span></div>
		<div class="vmsb-legend-item"><i style="background: var(--gold);"></i> <span>Categories</span></div>
		<div class="vmsb-legend-item"><i style="background: var(--accent-purple);"></i> <span>Entities</span></div>
	</div>
</article>

<!-- Load D3.js -->
<script src="https://d3js.org/d3.v7.min.js"></script>

<script>
jQuery(function($) {
	const container = d3.select("#vmsb-graph-container");
	const svg = d3.select("#vmsb-graph-svg");
	const width = container.node().clientWidth;
	const height = container.node().clientHeight;

	let simulation;

	async function initGraph() {
		$("#vmsb-graph-loading").show();
		svg.selectAll("*").remove();

		try {
			const data = await VMSB.call('graph-data', { limit: 200 });
			$("#vmsb-graph-loading").hide();

			if (!data.nodes || !data.nodes.length) {
				container.append("div").attr("class", "vmsb-note").style("text-align", "center").style("padding", "100px").text("No graph data found. Rebuild the Digital Twin to generate edges.");
				return;
			}

			const g = svg.append("g");

			// Zoom support
			svg.call(d3.zoom().on("zoom", (event) => {
				g.attr("transform", event.transform);
			}));

			simulation = d3.forceSimulation(data.nodes)
				.force("link", d3.forceLink(data.links).id(d => d.id).distance(100))
				.force("charge", d3.forceManyBody().strength(-150))
				.force("center", d3.forceCenter(width / 2, height / 2));

			const link = g.append("g")
				.attr("stroke", "rgba(255,255,255,0.08)")
				.selectAll("line")
				.data(data.links)
				.join("line");

			const node = g.append("g")
				.selectAll("g")
				.data(data.nodes)
				.join("g")
				.call(drag(simulation));

			node.append("circle")
				.attr("r", d => d.type === 'post' ? 8 : 5)
				.attr("fill", d => {
					if (d.type === 'post') return "var(--accent-blue)";
					if (d.type === 'category') return "var(--gold)";
					return "var(--accent-purple)";
				})
				.attr("stroke", "rgba(255,255,255,0.2)")
				.attr("stroke-width", 1.5);

			node.append("text")
				.text(d => d.name)
				.attr("x", 12)
				.attr("y", 4)
				.style("font-size", "10px")
				.style("fill", "var(--muted)")
				.style("pointer-events", "none");

			simulation.on("tick", () => {
				link
					.attr("x1", d => d.source.x)
					.attr("y1", d => d.source.y)
					.attr("x2", d => d.target.x)
					.attr("y2", d => d.target.y);

				node
					.attr("transform", d => `translate(${d.x},${d.y})`);
			});

		} catch (e) {
			console.error("Graph Error:", e);
		}
	}

	function drag(simulation) {
		function dragstarted(event) {
			if (!event.active) simulation.alphaTarget(0.3).restart();
			event.subject.fx = event.subject.x;
			event.subject.fy = event.subject.y;
		}
		function dragged(event) {
			event.subject.fx = event.x;
			event.subject.fy = event.y;
		}
		function dragended(event) {
			if (!event.active) simulation.alphaTarget(0);
			event.subject.fx = null;
			event.subject.fy = null;
		}
		return d3.drag()
			.on("start", dragstarted)
			.on("drag", dragged)
			.on("end", dragended);
	}

	initGraph();
	$("#vmsb-refresh-graph").on("click", initGraph);
});
</script>
