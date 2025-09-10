# zabbix-widgets-graph
An enhanced Pie Chart Widget for the Zabbix Dashboard

## Functionality and Features
- Adds a percentage of the pie in the tooltip when hovering over a pie chart sector. This is important to show the percentage of the total that each metric comprises.
- Adds the ability to use built-in and usermacros in the Data set label field. By default, the pie chart widget presents each metric as "HOSTNAME: ITEMNAME". For example, you can transform your metric to just be the metric name by typing {ITEM.NAME} in the Data set label box. Additionally, the full range of macro functions are supported. For example, with a item name of "CPU Usage - MyProcess", you could change the legend value by doing something like this: {{ITEM.NAME}.regrepl("CPU Usage - ", "")} and it will display as "MyProcess"
- Adds the ability to aggregate by item name. This works hand-in-hand with the added ability to use macros in the Data set label. This makes for an incredibly powerful experience and permits new ways to aggregate data in the pie chart.

## Coming Soon...
- This widget has been modified to accept multiple itemids broadcasted from certain widgets in the gryan337 git repository. More documentation coming soon.
