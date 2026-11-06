// Tree-shaken ECharts: only the charts and components the results pages use.
// Add a chart type here (and rebuild) before using it in the page.
import * as echarts from 'echarts/core';
import { BarChart, LineChart } from 'echarts/charts';
import { GridComponent, LegendComponent, MarkLineComponent, TitleComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([BarChart, LineChart, GridComponent, LegendComponent, MarkLineComponent, TitleComponent, TooltipComponent, CanvasRenderer]);

window.echarts = echarts;
